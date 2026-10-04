<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\View\FormsIconAlias;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\QueryBuilder\View\QueryBuilderIconAlias;
use Filament\Schemas\View\SchemaIconAlias;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\View\TablesIconAlias;
use Filament\View\PanelsIconAlias;
use Filament\Widgets\View\WidgetsIconAlias;

final readonly class OutlinedIcons
{
    /**
     * @return array<string, Heroicon>
     */
    public static function aliases(): array
    {
        // Filament has no panel-wide icon variant: every alias it ships solid is mapped here.
        // Chevrons stay mini, the variant drawn for their 16 to 20px slots.
        return [
            ...self::actions(),
            ...self::forms(),
            ...self::panels(),
            ...self::tables(),
            ...self::components(),
        ];
    }

    /**
     * @return array<string, Heroicon>
     */
    private static function actions(): array
    {
        return [
            ActionsIconAlias::ACTION_GROUP => Heroicon::OutlinedEllipsisVertical,
            ActionsIconAlias::CREATE_ACTION_GROUPED => Heroicon::OutlinedPlus,
            ActionsIconAlias::DELETE_ACTION => Heroicon::OutlinedTrash,
            ActionsIconAlias::DELETE_ACTION_GROUPED => Heroicon::OutlinedTrash,
            ActionsIconAlias::DETACH_ACTION => Heroicon::OutlinedXMark,
            ActionsIconAlias::DISSOCIATE_ACTION => Heroicon::OutlinedXMark,
            ActionsIconAlias::EDIT_ACTION => Heroicon::OutlinedPencilSquare,
            ActionsIconAlias::EDIT_ACTION_GROUPED => Heroicon::OutlinedPencilSquare,
            ActionsIconAlias::EXPORT_ACTION_GROUPED => Heroicon::OutlinedArrowDownTray,
            ActionsIconAlias::FORCE_DELETE_ACTION => Heroicon::OutlinedTrash,
            ActionsIconAlias::FORCE_DELETE_ACTION_GROUPED => Heroicon::OutlinedTrash,
            ActionsIconAlias::IMPORT_ACTION_GROUPED => Heroicon::OutlinedArrowUpTray,
            ActionsIconAlias::REPLICATE_ACTION => Heroicon::OutlinedSquare2Stack,
            ActionsIconAlias::REPLICATE_ACTION_GROUPED => Heroicon::OutlinedSquare2Stack,
            ActionsIconAlias::RESTORE_ACTION => Heroicon::OutlinedArrowUturnLeft,
            ActionsIconAlias::RESTORE_ACTION_GROUPED => Heroicon::OutlinedArrowUturnLeft,
            ActionsIconAlias::VIEW_ACTION => Heroicon::OutlinedEye,
            ActionsIconAlias::VIEW_ACTION_GROUPED => Heroicon::OutlinedEye,
        ];
    }

    /**
     * @return array<string, Heroicon>
     */
    private static function forms(): array
    {
        return [
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_CLONE => Heroicon::OutlinedSquare2Stack,
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_DELETE => Heroicon::OutlinedTrash,
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_EDIT => Heroicon::OutlinedCog6Tooth,
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_MOVE_DOWN => Heroicon::OutlinedArrowDown,
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_MOVE_UP => Heroicon::OutlinedArrowUp,
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_REORDER => Heroicon::OutlinedArrowsUpDown,
            FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_DOWN => Heroicon::OutlinedArrowDownCircle,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_LEFT => Heroicon::OutlinedArrowLeftCircle,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_RIGHT => Heroicon::OutlinedArrowRightCircle,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_UP => Heroicon::OutlinedArrowUpCircle,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ROTATE_LEFT => Heroicon::OutlinedArrowUturnLeft,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ROTATE_RIGHT => Heroicon::OutlinedArrowUturnRight,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_100 => Heroicon::OutlinedArrowsPointingOut,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_IN => Heroicon::OutlinedMagnifyingGlassPlus,
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_OUT => Heroicon::OutlinedMagnifyingGlassMinus,
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_DELETE => Heroicon::OutlinedTrash,
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_REORDER => Heroicon::OutlinedArrowsUpDown,
            FormsIconAlias::COMPONENTS_MODAL_TABLE_SELECT_ACTIONS_SELECT => Heroicon::OutlinedPencilSquare,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_CLONE => Heroicon::OutlinedSquare2Stack,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_DELETE => Heroicon::OutlinedTrash,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_DOWN => Heroicon::OutlinedArrowDown,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_UP => Heroicon::OutlinedArrowUp,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_REORDER => Heroicon::OutlinedArrowsUpDown,
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCKS_CLOSE_BUTTON => Heroicon::OutlinedXMark,
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCK_DELETE_BUTTON => Heroicon::OutlinedTrash,
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCK_EDIT_BUTTON => Heroicon::OutlinedPencilSquare,
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_MERGE_TAGS_CLOSE_BUTTON => Heroicon::OutlinedXMark,
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_CREATE_OPTION => Heroicon::OutlinedPlus,
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_EDIT_OPTION => Heroicon::OutlinedPencilSquare,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_COPY => Heroicon::OutlinedClipboardDocumentList,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD => Heroicon::OutlinedEyeSlash,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD => Heroicon::OutlinedEye,
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_FALSE => Heroicon::OutlinedXMark,
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_TRUE => Heroicon::OutlinedCheck,
        ];
    }

    /**
     * @return array<string, Heroicon>
     */
    private static function panels(): array
    {
        return [
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE => Heroicon::OutlinedLockOpen,
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES => Heroicon::OutlinedArrowPath,
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP => Heroicon::OutlinedLockClosed,
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_DISABLE => Heroicon::OutlinedLockOpen,
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_SET_UP => Heroicon::OutlinedLockClosed,
            PanelsIconAlias::GLOBAL_SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
            PanelsIconAlias::PAGES_DASHBOARD_ACTIONS_FILTER => Heroicon::OutlinedFunnel,
            PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN => Heroicon::OutlinedArrowLeft,
            PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL => Heroicon::OutlinedArrowRight,
            PanelsIconAlias::TENANT_MENU_BILLING_BUTTON => Heroicon::OutlinedCreditCard,
            PanelsIconAlias::TENANT_MENU_PROFILE_BUTTON => Heroicon::OutlinedCog6Tooth,
            PanelsIconAlias::TENANT_MENU_REGISTRATION_BUTTON => Heroicon::OutlinedPlus,
            PanelsIconAlias::THEME_SWITCHER_DARK_BUTTON => Heroicon::OutlinedMoon,
            PanelsIconAlias::THEME_SWITCHER_LIGHT_BUTTON => Heroicon::OutlinedSun,
            PanelsIconAlias::THEME_SWITCHER_SYSTEM_BUTTON => Heroicon::OutlinedComputerDesktop,
            PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => Heroicon::OutlinedArrowLeftEndOnRectangle,
            PanelsIconAlias::USER_MENU_PROFILE_ITEM => Heroicon::OutlinedUserCircle,
            PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON => Heroicon::OutlinedArrowLeftEndOnRectangle,
        ];
    }

    /**
     * @return array<string, Heroicon>
     */
    private static function tables(): array
    {
        return [
            TablesIconAlias::ACTIONS_COLUMN_MANAGER => Heroicon::OutlinedViewColumns,
            TablesIconAlias::ACTIONS_DISABLE_REORDERING => Heroicon::OutlinedCheck,
            TablesIconAlias::ACTIONS_ENABLE_REORDERING => Heroicon::OutlinedArrowsUpDown,
            TablesIconAlias::ACTIONS_FILTER => Heroicon::OutlinedFunnel,
            TablesIconAlias::ACTIONS_GROUP => Heroicon::OutlinedRectangleStack,
            TablesIconAlias::ACTIONS_OPEN_BULK_ACTIONS => Heroicon::OutlinedEllipsisVertical,
            TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON => Heroicon::OutlinedXMark,
            TablesIconAlias::REORDER_HANDLE => Heroicon::OutlinedBars2,
            TablesIconAlias::SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
        ];
    }

    /**
     * @return array<string, Heroicon>
     */
    private static function components(): array
    {
        return [
            NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON => Heroicon::OutlinedXMark,
            QueryBuilderIconAlias::ADD_RULE_ACTION => Heroicon::OutlinedPlus,
            QueryBuilderIconAlias::CONSTRAINTS_BOOLEAN => Heroicon::OutlinedCheckCircle,
            QueryBuilderIconAlias::CONSTRAINTS_DATE => Heroicon::OutlinedCalendar,
            QueryBuilderIconAlias::CONSTRAINTS_NUMBER => Heroicon::OutlinedVariable,
            QueryBuilderIconAlias::CONSTRAINTS_RELATIONSHIP => Heroicon::OutlinedArrowsPointingOut,
            QueryBuilderIconAlias::CONSTRAINTS_SELECT => Heroicon::OutlinedChevronUpDown,
            QueryBuilderIconAlias::CONSTRAINTS_TEXT => Heroicon::OutlinedLanguage,
            QueryBuilderIconAlias::OR_GROUP_ADD_GROUP_ACTION => Heroicon::OutlinedPlus,
            QueryBuilderIconAlias::OR_GROUP_BLOCK => Heroicon::OutlinedSlash,
            SchemaIconAlias::COMPONENTS_TABS_MORE_TABS_BUTTON => Heroicon::OutlinedEllipsisHorizontal,
            WidgetsIconAlias::CHART_WIDGET_FILTER => Heroicon::OutlinedFunnel,
        ];
    }
}
