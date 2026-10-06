<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Application') }} — License Activation</title>
    <style>
        :root {
            --cv-bg: #f5f6f8;
            --cv-card: #ffffff;
            --cv-text: #1f2430;
            --cv-muted: #6b7280;
            --cv-border: #e3e5ea;
            --cv-primary: #2f5fef;
            --cv-primary-hover: #244bd1;
            --cv-danger: #d64545;
            --cv-danger-bg: #fdecec;
            --cv-success: #17845a;
            --cv-success-bg: #e9f8f1;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cv-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--cv-text);
            padding: 24px;
        }
        .cv-card {
            width: 100%;
            max-width: 420px;
            background: var(--cv-card);
            border: 1px solid var(--cv-border);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .cv-title {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 4px;
        }
        .cv-subtitle {
            font-size: 14px;
            color: var(--cv-muted);
            margin: 0 0 24px;
        }
        .cv-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .cv-status.is-valid { background: var(--cv-success-bg); color: var(--cv-success); }
        .cv-status.is-invalid { background: var(--cv-danger-bg); color: var(--cv-danger); }
        .cv-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
        .cv-field { margin-bottom: 16px; }
        .cv-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .cv-input {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid var(--cv-border);
            border-radius: 8px;
            outline: none;
            font-family: inherit;
        }
        .cv-input:focus { border-color: var(--cv-primary); }
        .cv-error {
            font-size: 13px;
            color: var(--cv-danger);
            margin-top: 6px;
        }
        .cv-button {
            width: 100%;
            padding: 11px 12px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            background: var(--cv-primary);
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        .cv-button:hover { background: var(--cv-primary-hover); }
        .cv-meta {
            margin-top: 20px;
            font-size: 12px;
            color: var(--cv-muted);
            line-height: 1.6;
        }
        .cv-success-banner {
            background: var(--cv-success-bg);
            color: var(--cv-success);
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="cv-card">
        <h1 class="cv-title">License Activation</h1>
        <p class="cv-subtitle">{{ config('app.name', 'This application') }} needs a valid license key to continue.</p>

        @if (session('corevisys_license_activated'))
            <div class="cv-success-banner">License activated successfully.</div>
        @endif

        @if ($status && $status->status !== 'not_activated')
            <div class="cv-status {{ $status->valid ? 'is-valid' : 'is-invalid' }}">
                <span class="cv-dot"></span>
                <span>
                    @if ($status->valid)
                        Currently active{{ $status->expiresAt ? ' — expires '.$status->expiresAt->toFormattedDateString() : '' }}.
                    @else
                        Current license status: <strong>{{ str_replace('_', ' ', $status->status) }}</strong>. Enter a valid key below.
                    @endif
                </span>
            </div>
        @endif

        <form method="POST" action="{{ route($routeName) }}">
            @csrf
            <div class="cv-field">
                <label class="cv-label" for="license_key">License Key</label>
                <input
                    type="password"
                    id="license_key"
                    name="license_key"
                    class="cv-input"
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                    autocomplete="new-password"
                    spellcheck="false"
                    autocapitalize="off"
                    autofocus
                >
                @error('license_key')
                    <div class="cv-error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="cv-button">
                {{ $status && $status->valid ? 'Re-activate with a new key' : 'Activate' }}
            </button>
        </form>

        <p class="cv-meta">
            Your license key was provided when you purchased or subscribed to this software.
            Contact your vendor if you've lost it.
        </p>
    </div>
</body>
</html>
