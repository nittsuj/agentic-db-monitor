<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Enums\DbDriver;
use App\Livewire\Forms\ConnectionPreflight;
use App\Livewire\Forms\ConnectionSshFields;
use App\Livewire\Forms\ConnectionSslFields;
use App\Models\Project;
use App\Services\Database\PermissionChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CreateProjectModal extends Component
{
    use ConnectionPreflight;
    use ConnectionSshFields;
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
     * Set once a successful check had something to show. The next save stores
     * the profile without re-testing, unless a connection field changes.
     */
    public bool $reviewing = false;

    /**
     * Any change to what was tested invalidates the reviewed result.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['driver', 'host', 'port', 'database', 'username', 'password', 'sslMode', 'sslCa'], true)
            || str_starts_with($property, 'ssh')) {
            $this->reviewing = false;
        }
    }

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
        ] + $this->connectionRules();
    }

    public function save(PermissionChecker $checker): void
    {
        $this->validate();
        $dbDriver = DbDriver::from($this->driver);

        if ($this->reviewing) {
            $this->store($dbDriver);

            return;
        }

        $this->resetFeedback();

        $result = $checker->check(
            driver: $dbDriver,
            host: $this->host,
            port: $this->port,
            database: $this->database,
            username: $this->username,
            password: $this->password,
            options: $this->connectionOptions(),
        );

        if (! $result->successful) {
            $this->errorMessage = $result->errorMessage;
            $this->applyPreflight($result);

            return;
        }

        $this->applyPreflight($result);

        // Missing features and admin users are shown first; saving then
        // takes a second, deliberate click.
        if ($result->needsReview()) {
            $this->reviewing = true;

            return;
        }

        $this->store($dbDriver);
    }

    protected function store(DbDriver $dbDriver): void
    {
        $existing = Project::findDuplicate($dbDriver, $this->host, $this->port, $this->database, $this->username);

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
            'options' => $this->connectionOptions(),
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
        $this->reviewing = false;
        $this->clearPreflight();
    }

    public function render(): View
    {
        return view('livewire.projects.create-project-modal');
    }
}
