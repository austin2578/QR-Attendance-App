<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>QR Attendance App</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="styles.css" />
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
    .card { max-width: 420px; margin: 0 auto; padding: 1.5rem; border: 1px solid #ddd; border-radius: 12px; }
    .tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
    .tabs button { flex: 1; padding: 0.75rem; border: 1px solid #ccc; background: #f8f8f8; cursor: pointer; border-radius: 8px; }
    .tabs button.active { background: #fff; border-color: #888; font-weight: 600; }
    form { display: grid; gap: 0.75rem; }
    input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 0.6rem 0.7rem; border: 1px solid #ccc; border-radius: 8px; }
    .roles { display: flex; gap: 1rem; align-items: center; }
    .actions { display: flex; gap: 0.75rem; align-items: center; }
    button[type="submit"] { padding: 0.7rem 1rem; border: none; background: #222; color: #fff; border-radius: 8px; cursor: pointer; }
    #msg { margin-top: 0.75rem; min-height: 1.25rem; }
    .muted { color: #666; font-size: 0.9rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>QR Attendance System</h1>

    <div class="tabs" role="tablist" aria-label="Auth tabs">
      <button id="tabLogin" class="active" role="tab" aria-selected="true" aria-controls="panelLogin">Login</button>
      <button id="tabRegister" role="tab" aria-selected="false" aria-controls="panelRegister">Register</button>
    </div>

    <!-- LOGIN -->
    <section id="panelLogin" role="tabpanel" aria-labelledby="tabLogin">
      <form id="loginForm" autocomplete="on">
        <input type="email" id="login_email" placeholder="Email" required />
        <input type="password" id="login_password" placeholder="Password" required />
        <div class="actions">
          <button type="submit">Login</button>
          <span class="muted">You’ll be redirected to your dashboard.</span>
        </div>
      </form>
    </section>

    <!-- REGISTER -->
    <section id="panelRegister" role="tabpanel" aria-labelledby="tabRegister" hidden>
      <form id="registerForm" autocomplete="on">
        <input type="text" id="reg_name" placeholder="Full name" required />
        <input type="email" id="reg_email" placeholder="Email" required />
        <input type="password" id="reg_password" placeholder="Password" required />
        <input type="password" id="reg_confirm" placeholder="Confirm password" required />
        <div class="roles">
          <label><input type="radio" name="reg_role" value="student" checked /> Student</label>
          <label><input type="radio" name="reg_role" value="teacher" /> Teacher</label>
        </div>
        <div class="actions">
          <button type="submit">Create Account</button>
          <span class="muted">We’ll sign you in on success.</span>
        </div>
      </form>
    </section>

    <p id="msg" aria-live="polite"></p>
  </div>

  <script>
    const tabLogin = document.getElementById('tabLogin');
    const tabRegister = document.getElementById('tabRegister');
    const panelLogin = document.getElementById('panelLogin');
    const panelRegister = document.getElementById('panelRegister');
    const msg = document.getElementById('msg');

    function showTab(which) {
      const loginActive = which === 'login';
      tabLogin.classList.toggle('active', loginActive);
      tabRegister.classList.toggle('active', !loginActive);
      tabLogin.setAttribute('aria-selected', loginActive);
      tabRegister.setAttribute('aria-selected', !loginActive);
      panelLogin.hidden = !loginActive;
      panelRegister.hidden = loginActive;
    }
    tabLogin.addEventListener('click', () => showTab('login'));
    tabRegister.addEventListener('click', () => showTab('register'));

    function goToDashboard(role) {
      const file = role === 'teacher' ? 'teacher_dashboard.html' : 'student_dashboard.html';
      window.location.assign(`public/${file}`);
    }

    (function autoRedirectIfLoggedIn() {
      const token = localStorage.getItem('auth_token');
      const role = localStorage.getItem('role');
      if (token && role) goToDashboard(role);
    })();

    async function parseJsonOrThrow(res) {
      const text = await res.text();
      try { return JSON.parse(text); }
      catch (e) { throw new Error(`Non-JSON response (${res.status}): ${text.slice(0, 400)}`); }
    }

    // LOGIN
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      msg.textContent = 'Signing in...';
      try {
        const res = await fetch('api/auth.php?action=login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: document.getElementById('login_email').value.trim(),
            password: document.getElementById('login_password').value
          })
        });
        const resp = await parseJsonOrThrow(res);
        if (resp.success) {
          localStorage.setItem('auth_token', resp.token);
          localStorage.setItem('user_id', resp.user_id);
          localStorage.setItem('role', resp.role);
          localStorage.setItem('name', resp.name);
          goToDashboard(resp.role);
        } else {
          msg.textContent = `Login error: ${resp.error || 'Unknown error'}`;
        }
      } catch (err) {
        msg.textContent = `Network/Server error while logging in: ${err.message}`;
        console.error(err);
      }
    });

    // REGISTER
    document.getElementById('registerForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      msg.textContent = 'Creating your account...';
      const name = document.getElementById('reg_name').value.trim();
      const email = document.getElementById('reg_email').value.trim();
      const password = document.getElementById('reg_password').value;
      const confirm = document.getElementById('reg_confirm').value;
      const role = (document.querySelector('input[name="reg_role"]:checked') || {}).value;
      if (password !== confirm) { msg.textContent = 'Passwords do not match.'; return; }

      try {
        const res = await fetch('api/auth.php?action=register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, email, password, role })
        });
        const resp = await parseJsonOrThrow(res);
        if (resp.success) {
          // Prefer auto-login if token provided
          if (resp.token && resp.role) {
            localStorage.setItem('auth_token', resp.token);
            localStorage.setItem('user_id', resp.user_id);
            localStorage.setItem('role', resp.role);
            localStorage.setItem('name', resp.name || name);
            goToDashboard(resp.role);
          } else {
            msg.textContent = 'Account created. Please log in.';
            showTab('login');
          }
        } else {
          msg.textContent = `Registration error: ${resp.error || 'Unknown error'}`;
        }
      } catch (err) {
        msg.textContent = `Network/Server error while registering: ${err.message}`;
        console.error(err);
      }
    });
  </script>
</body>
</html>
