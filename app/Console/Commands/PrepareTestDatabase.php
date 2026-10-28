<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareTestDatabase extends Command
{
    protected $signature = 'dev:prepare-test-db {--database=quanlivanchuyen_testing}';

    protected $description = 'Tạo database MySQL kiểm thử riêng và chạy migration; không xóa dữ liệu.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Chỉ chạy ở local/testing.');

            return 1;
        }
        $name = $this->option('database');
        if (! preg_match('/^quanlivanchuyen_(testing|verify_[0-9]+)$/', $name)) {
            $this->error('Tên database kiểm thử không hợp lệ.');

            return 1;
        }
        $connection = config('database.connections.mysql');
        $connection['database'] = null;
        config(['database.connections.test_setup' => $connection]);
        DB::connection('test_setup')->statement('CREATE DATABASE IF NOT EXISTS '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name]);
        DB::purge('mysql');
        $this->info('Chạy migration trên '.$name);

        return $this->call('migrate', ['--force' => true]);
    }
}
