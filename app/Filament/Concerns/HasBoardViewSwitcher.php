<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\Flowforge\BoardResourcePage;

/**
 * Puts the list/board view switcher at the start of the row under the topbar,
 * so it keeps a stable position when toggling between the two layouts.
 */
trait HasBoardViewSwitcher
{
    public function getHeader(): ?View
    {
        $header = parent::getHeader();

        if (! $this instanceof BoardResourcePage || ! $header instanceof View) {
            return $header;
        }

        return view('filament.app.board-header', [
            'boardToolbar' => $header,
            'heading' => $this->getHeading(),
            'viewSwitcher' => $this->getViewSwitcher(),
        ]);
    }

    public function getViewSwitcher(): ?Htmlable
    {
        $resource = static::getResource();
        $pages = $resource::getPages();

        if (! isset($pages['board'])) {
            return null;
        }

        /** @var class-string<BoardResourcePage> $boardPage */
        $boardPage = $pages['board']->getPage();

        if (! $boardPage::canAccess()) {
            return null;
        }

        $switcher = view('filament.app.view-switcher', [
            'active' => $this instanceof BoardResourcePage ? 'board' : 'list',
            'listUrl' => $resource::getUrl('index'),
            'boardUrl' => $resource::getUrl('board'),
        ])->render();

        return new HtmlString($switcher);
    }
}
