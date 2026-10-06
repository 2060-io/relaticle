<?php

declare(strict_types=1);

return [

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'hello@relaticle.com'),
    ],

    'enterprise' => [
        'starting_price_yearly' => 20_000,
    ],

    'company' => [
        'name' => env('RELATICLE_COMPANY_NAME', 'Relaticle'),
        'address' => env('RELATICLE_COMPANY_ADDRESS', ''),
    ],

    'deletion' => [
        'grace_period_days' => 30,
        'reminder_days_before' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | How many workspaces one user may own. Workspaces they were invited into
    | belong to someone else and never count. A workspace scheduled for
    | deletion still occupies a slot until the grace period above elapses.
    |
    */

    'workspaces' => [
        'max_owned_per_user' => (int) env('RELATICLE_MAX_OWNED_WORKSPACES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Access
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of email addresses allowed to open the Horizon
    | dashboard outside the local environment. Empty denies everyone, so a
    | deployment that never sets this exposes nothing.
    |
    */

    'horizon' => [
        'admin_emails' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('HORIZON_ADMIN_EMAILS', '')),
        ))),
    ],

    'features' => [
        'account_deletion' => (bool) env('RELATICLE_FEATURE_ACCOUNT_DELETION', false),
        'onboard_seed' => (bool) env('RELATICLE_FEATURE_ONBOARD_SEED', true),
        'social_auth' => (bool) env('RELATICLE_FEATURE_SOCIAL_AUTH', true),
        'documentation' => (bool) env('RELATICLE_FEATURE_DOCUMENTATION', true),
        'email_integration' => (bool) env('RELATICLE_FEATURE_EMAIL_INTEGRATION', false),
        'billing' => (bool) env('RELATICLE_FEATURE_BILLING', false),
        'signup_challenge' => (bool) env('RELATICLE_FEATURE_SIGNUP_CHALLENGE', false),
        'support_menu' => (bool) env('RELATICLE_FEATURE_SUPPORT_MENU', false),
        'blog' => (bool) env('RELATICLE_FEATURE_BLOG', false),
        'setup_conversation' => (bool) env('RELATICLE_FEATURE_SETUP_CONVERSATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration access control
    |--------------------------------------------------------------------------
    |
    | When invitation_only is true, a new account can only be created for an
    | email address that has at least one unexpired pending team invitation.
    | This applies to both password registration and social login
    | (Google/GitHub) — an uninvited email cannot bootstrap an account.
    | Existing users are unaffected. Defaults to false so the very first
    | account/team can be created.
    |
    */

    'registration' => [
        'invitation_only' => (bool) env('RELATICLE_REGISTRATION_INVITATION_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team creation access control
    |--------------------------------------------------------------------------
    |
    | When creation_admins_only is true, only a user who owns or is an
    | Administrator of at least one existing team may create new teams.
    | Defaults to false so the first team can be created by anyone.
    |
    */

    'teams' => [
        'creation_admins_only' => (bool) env('RELATICLE_TEAM_CREATION_ADMINS_ONLY', false),
    ],

];
