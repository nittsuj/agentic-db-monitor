<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Enums\DbDriver;
use App\Livewire\Forms\ConnectionSslFields;
use App\Models\Project;
use App\Services\Database\PermissionChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CreateProjectModal extends Component
{
    use ConnectionSslFields;

    public string $name = '';

    public string $driver = 'mysql';

    public string $host = '127.0.0.1';

    public int $port = 3306;

    public string $database = '';

    public string $username = 'root';

    public string $password = '';

    public bool $isTesting = false;

    public ?string $errorMessage = null;

    /**
     * @var list<string>
     */
    public array $passedChecks = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public function updatedDriver(string $driver): void
    {
        $dbDriver = DbDriver::tryFrom($driver);
        if ($dbDriver !== null) {
            $this->port = $dbDriver->defaultPort();
            if ($dbDriver === DbDriver::PgSql && $this->username === 'root') {
                $this->username = 'postgres';
            } elseif ($dbDriver === DbDriver::MySql && $this->username === 'postgres') {
                $this->username = 'root';
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::enum(DbDriver::class)],
            'host' => ['required', 'string'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ] + $this->sslRules();
    }

    public function save(PermissionChecker $checker): void
    {
        $this->validate();
        $this->resetFeedback();

        $dbDriver = DbDriver::from($this->driver);

        $result = $checker->check(
            driver: $dbDriver,
            host: $this->host,
            port: $this->port,
            database: $this->database,
            username: $this->username,
            password: $this->password,
            options: $this->sslOptions(),
        );

        if (! $result->successful) {
            $this->errorMessage = $result->errorMessage;
            $this->passedChecks = $result->passedChecks;
            $this->warnings = $result->warnings;

            return;
        }

        // Check unique tuple
        $existing = Project::where('driver', $dbDriver)
            ->where('host', $this->host)
            ->where('port', $this->port)
            ->where('database', $this->database)
            ->where('username', $this->username)
            ->first();

        if ($existing !== null) {
            $this->errorMessage = 'A database profile with these exact connection details already exists.';

            return;
        }

        $project = Project::create([
            'name' => $this->name,
            'driver' => $dbDriver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'options' => $this->sslOptions(),
            'is_active' => true,
        ]);

        session(['active_project_id' => $project->id]);

        $this->dispatch('project-created', projectId: $project->id);
        $this->dispatch('modal-close', name: 'create-project-modal');

        $this->redirectRoute('dashboard');
    }

    public function resetFeedback(): void
    {
        $this->errorMessage = null;
        $this->passedChecks = [];
        $this->warnings = [];
    }

    public function render(): View
    {
        return view('livewire.projects.create-project-modal');
    }
}
