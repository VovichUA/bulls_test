<?php

namespace App\Console\Commands;

use App\Enums\SyncRunTriggerType;
use App\Exceptions\SheetImportException;
use App\Services\SyncDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('sheets:sync {--source= : Source configuration ID} {--tab= : Tab configuration ID} {--date= : Manual snapshot date (Y-m-d)} {--scheduled : Apply scheduled eligibility and today-only rules}')]
#[Description('Queue Google Sheets imports, one job per active tab')]
class SyncSheets extends Command
{
    public function handle(SyncDispatcher $dispatcher): int
    {
        $filters = [];
        foreach (['source', 'tab'] as $name) {
            $value = $this->option($name);
            if ($value !== null && (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                $this->error('Source and tab filters must be positive integer configuration IDs.');

                return self::FAILURE;
            }
            $filters[$name] = $value === null ? null : (int) $value;
        }
        try {
            $count = $dispatcher->enqueue($filters['source'], $filters['tab'], $this->option('date'), $this->option('scheduled') ? SyncRunTriggerType::Scheduled : SyncRunTriggerType::Manual);
            $this->info('Queued '.$count.' tab job(s).');

            return self::SUCCESS;
        } catch (SheetImportException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('Dispatch failed due to an internal or database error. Check the queue and application configuration.');
        }

        return self::FAILURE;
    }
}
