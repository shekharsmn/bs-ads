<?php

return [

    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),

    'client_id' => env('GOOGLE_OAUTH_CLIENT_ID', ''),

    'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', ''),

    /*
    | Must match an authorized redirect URI in Google Cloud Console (OAuth 2.0 Web client).
    | With Vite proxy, use the SPA origin so the browser keeps Sanctum session cookies:
    | http://localhost:5173/api/oauth/google/callback
    */
    'oauth_redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI', 'http://localhost:5173/api/oauth/google/callback'),

    /*
    | Where to send the browser after OAuth completes (pick Google Ads customer).
    */
    'frontend_oauth_complete_url' => env('GOOGLE_OAUTH_FRONTEND_COMPLETE_URL', 'http://localhost:5173/accounts/google/callback'),

];
