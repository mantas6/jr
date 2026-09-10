<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->registerDevProcesses();
    }

    /**
     * Register additional processes run by `php artisan dev` (and `composer run dev`).
     *
     * The framework's default dev processes do not include the scheduler, so the
     * every-15-minute Jira sync would never fire locally. Registering `schedule:work`
     * here keeps the schedule running alongside the server, queue and Vite processes.
     */
    protected function registerDevProcesses(): void
    {
        DevCommands::artisan('schedule:work', 'schedule');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Table::configureUsing(fn (Table $table): Table => $table
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->defaultPaginationPageOption(50));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
