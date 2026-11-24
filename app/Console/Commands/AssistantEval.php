<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\Project;
use App\Services\Ai\AiProviderConfig;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use App\Services\Eval\AssistantEvaluator;
use App\Services\Eval\EvalCase;
use App\Services\Eval\EvalCaseFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AssistantEval extends Command
{
    protected $signature = 'assistant:eval
                            {project : Project name or id to ask about}
                            {--provider= : AI setting id or provider name (defaults to the active one)}
                            {--timeout= : Seconds per answer (defaults to the app\'s own limit)}
                            {--category= : Only run questions of this category}
                            {--no-think : Switch off the reasoning step of the model (Ollama only); faster, maybe less accurate}
                            {--list : Show the questions and expected answers without asking the assistant}
                            {--json= : Also write the full results to this file}';

    protected $description = 'Ask the chat assistant questions about a real database and score its answers against the database itself.';

    public function handle(TargetConnectionManager $connections): int
    {
        $project = Project::query()
            ->where('name', $this->argument('project'))
            ->orWhere('id', $this->argument('project'))
            ->first();

        if ($project === null) {
            $this->error('No project named or with id "'.$this->argument('project').'".');

            return self::FAILURE;
        }

        $connectionName = $connections->connectionName($project);

        try {
            $connection = $connections->connect($project);
            $driver = DiagnosticTools::driverFor($project->driver->value);
            $cases = EvalCaseFactory::build($connection, $driver);

            if ($this->option('category')) {
                $cases = array_values(array_filter($cases, fn (EvalCase $case): bool => $case->category === $this->option('category')));
            }

            if ($cases === []) {
                $this->error('No questions to ask: the database has no tables, or none match --category.');

                return self::FAILURE;
            }

            if ($this->option('list')) {
                $this->table(['Id', 'Question', 'Correct answer must contain'], array_map(fn (EvalCase $case): array => [
                    $case->id,
                    $case->question,
                    implode(', ', $case->mustMention) ?: 'one of: '.Str::limit(implode(' | ', $case->mustMentionAny), 60),
                ], $cases));

                return self::SUCCESS;
            }

            $setting = $this->aiSetting();
            if ($setting === false) {
                return self::FAILURE;
            }

            $aiConfig = AiProviderConfig::resolve($setting);
            $timeout = (int) ($this->option('timeout') ?: AiProviderConfig::TIMEOUT_SECONDS);
            $thinking = ! $this->option('no-think');

            if (! $thinking && ! AssistantEvaluator::canDisableThinking($aiConfig)) {
                $this->warn('--no-think only works with Ollama; this provider keeps its default.');
            }

            $evaluator = new AssistantEvaluator($connection, $driver, $aiConfig, $timeout, $thinking);

            $this->info("Asking {$aiConfig['provider']} / {$aiConfig['model']} ".count($cases)." questions about {$project->name} ({$timeout}s per answer".($thinking ? '' : ', thinking off').')…');

            $results = [];
            $errorsInARow = 0;
            foreach ($cases as $i => $case) {
                // Answers can take minutes on a local model; show what is being waited on.
                $this->output->write(sprintf('%2d/%d  asking: %s … ', $i + 1, count($cases), Str::limit($case->question, 70)));
                $result = $evaluator->run($case);
                $results[] = $result;
                $this->output->write("\r\033[2K");

                $this->line(sprintf(
                    '%2d. %s %-40s %6.1fs  %s',
                    $i + 1,
                    $result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>',
                    Str::limit($case->id, 40),
                    $result['seconds'],
                    Str::limit($result['reason'], 80),
                ));

                $errorsInARow = $result['error'] === null ? 0 : $errorsInARow + 1;
                if ($errorsInARow === 3 && $i + 1 < count($cases)) {
                    $this->error('Stopped: the last 3 questions got no answer, so the provider looks unreachable.');
                    break;
                }
            }
        } finally {
            $connections->purge($connectionName);
        }

        $scored = $this->summary($results);

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'project' => $project->name,
                'provider' => $aiConfig['provider'],
                'model' => $aiConfig['model'],
                'timeout_seconds' => $timeout,
                'thinking' => $thinking,
                'ran_at' => now()->toIso8601String(),
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->line("Full results written to {$path}");
        }

        return $scored ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Questions that got no answer (timeouts, connection errors) say nothing about
     * correctness, so they are reported apart and left out of the score.
     *
     * @param  list<array{category: string, passed: bool, error: string|null, seconds: float}>  $results
     * @return bool whether any question was answered, i.e. whether there is a score
     */
    private function summary(array $results): bool
    {
        $score = AssistantEvaluator::score($results);

        $rows = [];
        foreach (collect($results)->groupBy('category') as $category => $group) {
            $noAnswer = $group->whereNotNull('error')->count();
            $rows[] = [
                $category,
                $group->where('passed', true)->count().'/'.($group->count() - $noAnswer),
                $noAnswer ?: '',
            ];
        }

        $this->newLine();
        $this->table(['Category', 'Correct', 'No answer'], $rows);

        if ($score['percent'] === null) {
            $this->error('No score: none of the '.count($results).' questions got an answer. Check the provider and the network, then run again.');

            return false;
        }

        $this->info("Correctness: {$score['passed']}/{$score['answered']} answered questions ({$score['percent']}%)");

        if ($score['unanswered'] > 0) {
            $this->warn("{$score['unanswered']} question(s) got no answer (timeout or connection error) and are not in the score.");
        }

        $this->line("Average time per answer: {$score['average_seconds']}s");

        return true;
    }

    private function aiSetting(): AiSetting|false|null
    {
        $choice = $this->option('provider');

        if ($choice === null) {
            return AiSetting::where('is_active', true)->first();
        }

        $setting = AiSetting::where('id', $choice)->orWhere('provider', $choice)->first();

        if ($setting === null) {
            $this->error("No AI setting with id or provider \"{$choice}\".");

            return false;
        }

        return $setting;
    }
}
