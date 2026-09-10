<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * @property-read Schema $form
 */
class JiraConnection extends Page
{
    /**
     * The current state of the connection form.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];
    protected string $view = 'filament.pages.jira-connection';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Link;

    protected static ?string $navigationLabel = 'Jira connection';

    protected static ?int $navigationSort = 20;

    public function mount(): void
    {
        $this->fillFormFromUser();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('How to connect')
                        ->description('Create an Atlassian API token, then paste it below along with your Jira details.')
                        ->schema([
                            Text::make(new HtmlString(
                                '1. Open <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener noreferrer" class="fi-link">id.atlassian.com/manage-profile/security/api-tokens</a>.'
                            )),
                            Text::make('2. Create API token → give it a label → Create → copy it (shown once) → paste it below.'),
                            Text::make('3. Site URL is what you see in the browser, e.g. https://yourcompany.atlassian.net.'),
                            Text::make('4. Project key is the prefix of issue keys, e.g. PROJ in PROJ-123.'),
                        ]),
                    Section::make('Credentials')
                        ->schema([
                            TextInput::make('jira_site_url')
                                ->label('Site URL')
                                ->placeholder('https://yourcompany.atlassian.net')
                                ->url()
                                ->required()
                                ->maxLength(255),
                            TextInput::make('jira_email')
                                ->label('Atlassian email')
                                ->email()
                                ->required()
                                ->maxLength(255),
                            TextInput::make('jira_api_token')
                                ->label('API token')
                                ->password()
                                ->revealable()
                                ->placeholder($this->hasStoredToken() ? 'Leave blank to keep the current token' : null)
                                ->required(fn (): bool => !$this->hasStoredToken())
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->maxLength(255),
                            TextInput::make('jira_project_key')
                                ->label('Project key')
                                ->placeholder('PROJ')
                                ->required()
                                ->alphaDash()
                                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper($state))
                                ->maxLength(255),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save & verify')
                                ->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = $this->getUser();
        $token = $data['jira_api_token'] ?? $user->jira_api_token;

        $candidate = new User([
            'jira_site_url' => $data['jira_site_url'],
            'jira_email' => $data['jira_email'],
        ]);
        $candidate->jira_api_token = $token;

        try {
            $me = JiraClient::forUser($candidate)->me();
        } catch (JiraApiException $exception) {
            Notification::make()
                ->danger()
                ->title('Could not connect to Jira')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $user->forceFill([
            'jira_site_url' => $data['jira_site_url'],
            'jira_email' => $data['jira_email'],
            'jira_api_token' => $token,
            'jira_project_key' => $data['jira_project_key'],
            'jira_account_id' => $me['accountId'] ?? null,
            'jira_connected_at' => now(),
            'jira_last_sync_error' => null,
        ])->save();

        $this->fillFormFromUser();

        Notification::make()
            ->success()
            ->title('Connected to Jira as '.($me['displayName'] ?? $user->jira_email))
            ->send();
    }

    public function disconnect(): void
    {
        $this->getUser()->forceFill([
            'jira_site_url' => null,
            'jira_email' => null,
            'jira_api_token' => null,
            'jira_account_id' => null,
            'jira_project_key' => null,
            'jira_connected_at' => null,
            'jira_last_synced_at' => null,
            'jira_last_full_synced_at' => null,
            'jira_last_sync_error' => null,
        ])->save();

        $this->fillFormFromUser();

        Notification::make()
            ->success()
            ->title('Disconnected from Jira')
            ->send();
    }

    /**
     * A short human-readable status of the current connection.
     */
    public function getConnectionStatus(): string
    {
        $user = $this->getUser();

        if (!$user->hasJiraConnection()) {
            return 'Not connected';
        }

        return 'Connected as '.($user->jira_email).' since '.$user->jira_connected_at?->diffForHumans();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('disconnect')
                ->label('Disconnect')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getUser()->hasJiraConnection())
                ->action(fn () => $this->disconnect()),
        ];
    }

    protected function fillFormFromUser(): void
    {
        $user = $this->getUser();

        $this->form->fill([
            'jira_site_url' => $user->jira_site_url,
            'jira_email' => $user->jira_email,
            'jira_project_key' => $user->jira_project_key,
        ]);
    }

    protected function hasStoredToken(): bool
    {
        return filled($this->getUser()->jira_api_token);
    }

    protected function getUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
