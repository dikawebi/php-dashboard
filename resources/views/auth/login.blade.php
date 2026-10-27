<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login Dashboard</title><link rel="stylesheet" href="{{ asset('css/dashboard.css') }}"></head>
<body class="login-page">
    <div class="login-card">
        <img src="{{ asset('images/logo-bp.svg') }}" alt="Logo perusahaan" class="login-logo">
        <h1>Mining Accounting Dashboard</h1>
        <p>Masuk untuk mengakses dashboard.</p>
        @if ($errors->any()) <div class="notice notice-error">{{ $errors->first() }}</div> @endif
        <form method="post" action="{{ route('login.attempt') }}">
            @csrf
            <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"></div>
            <div class="form-group"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
            <label class="remember-box"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
            <button class="btn btn-primary login-button">Masuk</button>
        </form>
    </div>
</body>
</html>
