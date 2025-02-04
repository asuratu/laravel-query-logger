<?php

namespace Overtrue\LaravelQueryLogger;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Illuminate\Support\Str;

class ServiceProvider extends LaravelServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if (!$this->app['config']->get('logging.query.enabled', false)) {
            return;
        }

        $trigger = $this->app['config']->get('logging.query.trigger');

        if (!empty($trigger) && !$this->requestHasTrigger($trigger)) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if ($query->time < $this->app['config']->get('logging.query.slower_than', 0)) {
                return;
            }

            $sqlWithPlaceholders = str_replace(['%', '?', '%s%s'], ['%%', '%s', '?'], $query->sql);

            $bindings = $query->connection->prepareBindings($query->bindings);
            $pdo = $query->connection->getPdo();
            $realSql = $sqlWithPlaceholders;
            $duration = $this->formatDuration($query->time / 1000);

            if (count($bindings) > 0) {
                $realSql = vsprintf($sqlWithPlaceholders, array_map([$pdo, 'quote'], $bindings));
            }

            // 忽略的日志
            if (Str::contains($realSql, config('logging.query.ignore_sql'))){
                return;
            }

            $requestUri = request()->getRequestUri();

            if (Str::contains($realSql, config('logging.query.admin_str'))) {
                $channel = config('logging.query.admin_channel', config('logging.default'));
            } else {
                $channel = config('logging.query.channel', config('logging.default'));
            }

            Log::channel($channel)->debug(sprintf('[%s] [%s] %s | %s: %s', $query->connection->getDatabaseName(), $duration, $realSql, request()->method(), $requestUri));
        });
    }

    /**
     * Register the application services.
     */
    public function register()
    {
    }

    /**
     * @param string $trigger
     * @return bool
     */
    public function requestHasTrigger(string $trigger): bool
    {
        return false !== getenv($trigger) || request()->hasHeader($trigger) || request()->has($trigger) || request()->hasCookie($trigger);
    }

    /**
     * Format duration.
     * @param float $seconds
     * @return string
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return round($seconds * 1000000).'μs';
        } elseif ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        return round($seconds, 2).'s';
    }
}