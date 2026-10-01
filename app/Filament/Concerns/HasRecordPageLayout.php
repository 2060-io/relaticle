<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Components\Infolists\RecordChipEntry;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Attributes\On;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\EmailIntegration\Filament\Infolists\CommunicationIntelligenceInfolist;

/**
 * @mixin ViewRecord
 */
trait HasRecordPageLayout
{
    private const int VISIBLE_DETAIL_COUNT = 8;

    /**
     * @return array<int, Entry>
     */
    abstract protected function nativeDetailEntries(): array;

    abstract protected function recordLangFile(): string;

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Flex::make([
                RecordChipEntry::make('name')
                    ->hiddenLabel()
                    ->chipSize('lg')
                    ->size(TextSize::Large)
                    ->weight(FontWeight::SemiBold),
                $this->recordActions()->grow(false),
            ])
                ->verticallyAlignCenter(),
            $this->detailsSection($schema),
            CommunicationIntelligenceInfolist::section()
                ->contained(false)
                ->extraAttributes(['class' => 'fi-record-rail-section']),
            $this->recordInfoSection(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Flex::make([
                Group::make([$this->getInfolistContentComponent()])
                    ->grow(false)
                    ->extraAttributes(['class' => 'fi-record-rail']),
                View::make('filament.app.record-rail-resize-handle')
                    ->grow(false),
                Group::make([$this->getRelationManagersContentComponent()])
                    ->extraAttributes(['class' => 'fi-record-pane']),
            ])
                ->from('xl')
                ->extraAttributes([
                    'class' => 'fi-record-layout',
                    'x-data' => 'recordLayout',
                ]),
        ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-record-page',
        ];
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getHeadingStart(): Htmlable
    {
        $resource = static::getResource();

        return new HtmlString(view('filament.app.record-breadcrumb', [
            'url' => $resource::getUrl('index'),
            'icon' => $resource::getNavigationIcon(),
            'label' => $resource::getTitleCasePluralModelLabel(),
        ])->render());
    }

    #[On('related-records-changed')]
    public function refreshRelatedRecordCounts(): void
    {
        resolve(PartialsComponentHook::class)->forceRender($this);
    }

    private function recordActions(): Actions
    {
        return Actions::make([
            EditAction::make()
                ->label(__("{$this->recordLangFile()}.pages.view.actions.edit.label"))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->size(Size::Small)
                ->after(function (): void {
                    $this->getRecord()->refresh()->load('customFieldValues.customField.options');

                    resolve(PartialsComponentHook::class)->forceRender($this);
                }),
            ActionGroup::make([
                ActionGroup::make([
                    $this->copyToClipboardAction(
                        'copyPageUrl',
                        __("{$this->recordLangFile()}.pages.view.actions.copy_page_url.label"),
                        static::getResource()::getUrl('view', [$this->getRecord()]),
                        __('filament/record-page.notifications.url_copied'),
                    ),
                    $this->copyToClipboardAction(
                        'copyRecordId',
                        __("{$this->recordLangFile()}.pages.view.actions.copy_record_id.label"),
                        (string) $this->getRecord()->getKey(),
                        __('filament/record-page.notifications.id_copied'),
                    ),
                ])->dropdown(false),
                DeleteAction::make(),
            ])
                ->button()
                ->hiddenLabel()
                ->color('gray')
                ->size(Size::Small)
                ->icon('heroicon-m-ellipsis-horizontal')
                ->dropdownPlacement('bottom-end'),
        ])
            ->key('recordActions')
            ->extraAttributes(['class' => 'fi-record-rail-actions']);
    }

    private function copyToClipboardAction(string $name, string $label, string $value, string $notification): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-clipboard-document')
            ->action(function () use ($value, $notification): void {
                $jsValue = Js::from($value);
                $jsNotification = Js::from($notification);

                $this->js("
                    navigator.clipboard.writeText({$jsValue}).then(() => {
                        new FilamentNotification()
                            .title({$jsNotification})
                            .success()
                            .send()
                    })
                ");
            });
    }

    private function detailsSection(Schema $schema): Section
    {
        $entries = collect($this->nativeDetailEntries())
            ->concat(CustomFields::infolist()->forSchema($schema)->values())
            ->map(fn (Entry $entry): Entry => $entry
                ->inlineLabel()
                ->columnSpan(['default' => 'full', 'lg' => 'full'])
                ->placeholder(__('filament/record-page.empty')))
            ->values();

        $visible = $entries->take(self::VISIBLE_DETAIL_COUNT)->all();
        $overflow = $entries->skip(self::VISIBLE_DETAIL_COUNT)->all();

        return Section::make(__('filament/record-page.sections.details'))
            ->contained(false)
            ->collapsible()
            ->schema([
                Group::make([
                    ...$visible,
                    ...$this->overflowDetails($overflow),
                ])
                    ->dense()
                    ->extraAttributes(['x-data' => '{ showAllDetails: false }']),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }

    /**
     * @param  array<int, Entry>  $overflow
     * @return array<int, Component>
     */
    private function overflowDetails(array $overflow): array
    {
        if ($overflow === []) {
            return [];
        }

        return [
            Group::make($overflow)->dense()->extraAttributes([
                'x-show' => 'showAllDetails',
                'x-cloak' => true,
            ]),
            View::make('filament.app.record-details-toggle'),
        ];
    }

    private function hasMemberCreator(Company|People|Opportunity $record): bool
    {
        return ! $record->isSystemCreated() && $record->creator !== null;
    }

    private function recordInfoSection(): Section
    {
        return Section::make(__('filament/record-page.sections.record_info'))
            ->contained(false)
            ->collapsible()
            ->collapsed()
            ->dense()
            ->inlineLabel()
            ->schema([
                RecordChipEntry::make('creator.name')
                    ->label(__('filament/record-page.fields.created_by'))
                    ->visible(fn (Company|People|Opportunity $record): bool => $this->hasMemberCreator($record)),
                TextEntry::make('created_by')
                    ->label(__('filament/record-page.fields.created_by'))
                    ->hidden(fn (Company|People|Opportunity $record): bool => $this->hasMemberCreator($record)),
                TextEntry::make('created_at')
                    ->label(__('filament/record-page.fields.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('filament/record-page.fields.updated_at'))
                    ->dateTime(),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }
}
