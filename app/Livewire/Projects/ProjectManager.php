<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Enums\DbDriver;
use App\Livewire\Forms\ConnectionSslFields;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Edit and delete a target database profile (FR-05). Deleting a project
 * removes its chat, metrics, thresholds, and pending actions through the
 * cascade rules; the audit log survives with a denormalised project name.
 */
class ProjectManager extends Component
{
    use ConnectionSslFields;

    public ?string $projectId = null;

    public string $name = '';

    public string $driver = 'mysql';

    public string $host = '127.0.0.1';

    public int $port = 3306;

    public string $database = '';

    public string $username = 'root';

    public string $password = '';

    public bool $confirmingDelete = false;

    public ?string $errorMessage = null;

    public ?string $notice = null;

    public function edit(string $id): void
    {
        $project = Project::findOrFail($id);

        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->driver = $project->driver->value;
        $this->host = $project->host;
        $this->port = $project->port;
        $this->database = $project->database;
        $this->username = $project->username;
        $this->password = '';
        $this->fillSslFrom($project);
        $this->confirmingDelete = false;
        $this->errorMessage = null;
        $this->notice = null;
    }

    public function updatedDriver(string $driver): void
    {
        $dbDriver = DbDriver::tryFrom($driver);

        if ($dbDriver === null) {
            return;
        }

        $this->port = $dbDriver->defaultPort();
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

    public function save(): void
    {
        $this->validate();
        $this->errorMessage = null;

        $project = Project::findOrFail($this->projectId);
        $dbDriver = DbDriver::from($this->driver);

        $duplicate = Project::query()
            ->where('driver', $dbDriver)
            ->where('host', $this->host)
            ->where('port', $this->port)
            ->where('database', $this->database)
            ->where('username', $this->username)
            ->whereKeyNot($project->id)
            ->exists();

        if ($duplicate) {
            $this->errorMessage = 'A database profile with these exact connection details already exists.';

            return;
        }

        $attributes = [
            'name' => $this->name,
            'driver' => $dbDriver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'options' => $this->sslOptions($project->options ?? []),
        ];

        // An empty password field keeps the stored credential.
        if ($this->password !== '') {
            $attributes['password'] = $this->password;
        }

        $project->update($attributes);

        $this->notice = 'Project "'.$project->name.'" diperbarui.';
        $this->password = '';
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        $project = Project::findOrFail($this->projectId);
        $name = $project->name;

        $project->delete();

        if (session('active_project_id') === $project->id) {
            session()->forget('active_project_id');
        }

        $this->projectId = null;
        $this->confirmingDelete = false;
        $this->notice = 'Project "'.$name.'" dihapus. Audit log tetap tersimpan.';
    }

    public function cancel(): void
    {
        $this->projectId = null;
        $this->confirmingDelete = false;
        $this->errorMessage = null;
    }

    public function render(): View
    {
        return view('livewire.projects.project-manager', [
            'projects' => Project::orderBy('name')->get(),
        ]);
    }
}
