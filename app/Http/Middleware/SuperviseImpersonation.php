<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Impersonation\StopImpersonationController;
use App\Support\Impersonation\Impersonator;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

final readonly class SuperviseImpersonation
{
    public function __construct(
        private Impersonator $impersonator,
        private StopImpersonationController $stopImpersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonator->active($request) || $request->routeIs('impersonation.*')) {
            return $next($request);
        }

        if ($this->impersonator->lapsed($request)) {
            return $this->end($request);
        }

        $this->recordWrite($request);

        return $next($request);
    }

    private function end(Request $request): RedirectResponse
    {
        if (! $request->hasHeader('X-Livewire') && ! $request->expectsJson()) {
            return ($this->stopImpersonation)($request);
        }

        $this->impersonator->stop($request);

        // A script cannot follow a redirect to another host, and Livewire offers a reload on 419.
        abort(419);
    }

    private function recordWrite(Request $request): void
    {
        if ($request->isMethodSafe() || $request->is('broadcasting/auth')) {
            return;
        }

        $actions = $this->livewireActions($request);

        if ($request->routeIs('*livewire.update') && $actions === []) {
            return;
        }

        $this->impersonator->record(
            'impersonation_request',
            $this->impersonator->administrator($this->impersonator->administratorId($request)),
            $request->user('web'),
            [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'actions' => $actions,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function livewireActions(Request $request): array
    {
        $actions = [];

        foreach (Arr::wrap($request->input('components')) as $component) {
            $snapshot = data_get($component, 'snapshot');
            $name = is_string($snapshot) ? data_get(json_decode($snapshot, true), 'memo.name') : null;

            if (! is_string($name)) {
                continue;
            }

            foreach (array_keys(Arr::wrap(data_get($component, 'updates'))) as $property) {
                $actions[] = "{$name}::\${$property}";
            }

            foreach (Arr::wrap(data_get($component, 'calls')) as $call) {
                $method = data_get($call, 'method');

                if (! is_string($method) || $method === '__lazyLoad') {
                    continue;
                }

                $mounted = $method === 'mountAction' ? data_get($call, 'params.0') : null;

                $actions[] = is_string($mounted) ? "{$name}::{$method}({$mounted})" : "{$name}::{$method}";
            }
        }

        return $actions;
    }
}
