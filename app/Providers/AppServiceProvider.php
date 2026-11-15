<?php

namespace App\Providers;

use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

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
        // File biểu thuế Excel ~32 MB, vượt mức 12 MB mặc định của Livewire. PHP cũng phải cho phép
        // upload_max_filesize / post_max_size >= 100M thì mới tải lên qua giao diện được.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:102400']]);

        Table::configureUsing(fn (Table $table): Table => $table
            ->paginated([20, 50, 100, 'all'])
            ->defaultPaginationPageOption(20));
    }
}
