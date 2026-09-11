const API_BASE = '../backend';

async function apiRequest(path, options = {}) {
  const response = await fetch(`${API_BASE}/${path}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || 'Request failed');
    error.data = data;
    throw error;
  }
  return data;
}

const api = {
  register: (payload) => apiRequest('register.php', { method: 'POST', body: JSON.stringify(payload) }),
  login: (payload) => apiRequest('login.php', { method: 'POST', body: JSON.stringify(payload) }),
  logout: () => apiRequest('logout.php', { method: 'POST' }),
};
