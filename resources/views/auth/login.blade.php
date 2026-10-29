<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#152c27">
    <title>Login | RMS Yaskawa</title>
    <style>
        :root{font-family:Inter,'Segoe UI',Arial,sans-serif;color:#182b28;background:#f5f7f5}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{width:100%;max-width:420px;background:#fff;border:1px solid #e4eae6;border-radius:18px;box-shadow:0 16px 65px #142b2516;overflow:hidden}
        .top{background:#152c27;color:#fff;padding:30px 32px}
        .brand{font-size:28px;font-weight:800;letter-spacing:.6px}.brand span{color:#9cdbba}
        .top p{margin:8px 0 0;font-size:13px;color:#bcd0c6}
        .content{padding:30px 32px 34px}h1{font-size:21px;margin:0 0 7px}p.subtitle{margin:0 0 24px;color:#697c71;font-size:13px}
        label{display:block;font-size:12px;font-weight:600;margin:17px 0 7px}
        input[type=email],input[type=password]{display:block;width:100%;padding:12px;border:1px solid #cddbd1;border-radius:9px;font:inherit;font-size:14px}
        input:focus{outline:2px solid #8dc9a9;outline-offset:1px}
        .remember{display:flex;gap:9px;align-items:center;margin:18px 0 22px;color:#597065;font-size:12px}
        .remember label{margin:0;font-weight:400}
        button{width:100%;padding:13px;border:0;border-radius:9px;background:#247b60;color:#fff;font:inherit;font-weight:650;cursor:pointer}
        button:hover{background:#1e6c54}
        .error{color:#a13333;font-size:12px;margin-top:7px}
        .foot{margin:20px 0 0;text-align:center;color:#8a9a8e;font-size:11px}
    </style>
</head>
<body>
<main class="card">
    <header class="top">
        <div class="brand">RMS<span>.</span></div>
        <p>Yaskawa Equipment Health Monitoring</p>
    </header>
    <section class="content">
        <h1>Masuk ke Dashboard</h1>
        <p class="subtitle">Gunakan akun RMS yang terdaftar di Laravel.</p>
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" maxlength="255" required autofocus>
            @error('email') <div class="error" role="alert">{{ $message }}</div> @enderror

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password') <div class="error" role="alert">{{ $message }}</div> @enderror

            <div class="remember">
                <input type="checkbox" name="remember" id="remember" value="1">
                <label for="remember">Ingat saya di perangkat ini</label>
            </div>
            <button type="submit">Masuk ke RMS</button>
        </form>
        <p class="foot">RMS Yaskawa · Internal Monitoring</p>
    </section>
</main>
</body>
</html>
