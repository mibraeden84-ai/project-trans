const API_BASE = 'http://192.168.1.100:8000/api/v1';

let authToken = null;

export function setToken(token) {
  authToken = token;
}

export function getToken() {
  return authToken;
}

async function request(endpoint, options = {}) {
  const url = `${API_BASE}${endpoint}`;
  const headers = { 'Content-Type': 'application/json', ...options.headers };
  if (authToken) headers['Authorization'] = `Bearer ${authToken}`;

  const res = await fetch(url, { ...options, headers });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || data.message || 'Request failed');
  return data;
}

export function login(username, password) {
  return request('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ username, password }),
  });
}

export function register(username, password, email) {
  return request('/auth/register', {
    method: 'POST',
    body: JSON.stringify({ username, password, email }),
  });
}

export function getMe() {
  return request('/auth/me');
}

export function updateProfile(data) {
  return request('/auth/me', { method: 'PUT', body: JSON.stringify(data) });
}

export function getBrands() {
  return request('/brands');
}

export function getBrand(slug) {
  return request(`/brands/${slug}`);
}

export function getModels(brandSlug) {
  return request(`/brands/${brandSlug}/models`);
}

export function getModel(brandSlug, modelSlug) {
  return request(`/brands/${brandSlug}/models/${modelSlug}`);
}

export function getFiles(type, params = {}) {
  const qs = new URLSearchParams(params).toString();
  return request(`/files/${type}${qs ? '?' + qs : ''}`);
}

export function getFile(type, id) {
  return request(`/files/${type}/${id}`);
}

export function downloadFile(type, id) {
  return request(`/files/${type}/${id}/download`);
}

export function deleteFile(type, id) {
  return request(`/files/${type}/${id}`, { method: 'DELETE' });
}

export function searchFiles(query) {
  return request(`/search?q=${encodeURIComponent(query)}`);
}

export function getStats() {
  return request('/stats');
}

export function getTopDownloads() {
  return request('/stats/top-downloads');
}

export function getAdminUsers() {
  return request('/admin/users');
}

export function getAdminActivity() {
  return request('/admin/activity');
}

export function getAdminHealth() {
  return request('/admin/health');
}

export function createBrand(data) {
  return request('/brands', { method: 'POST', body: JSON.stringify(data) });
}

export function updateBrand(id, data) {
  return request(`/brands/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export function deleteBrand(id) {
  return request(`/brands/${id}`, { method: 'DELETE' });
}

export function createModel(data) {
  return request('/models', { method: 'POST', body: JSON.stringify(data) });
}

export function updateModel(id, data) {
  return request(`/models/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export function deleteModel(id) {
  return request(`/models/${id}`, { method: 'DELETE' });
}

export function uploadFile(formData) {
  return request('/files/upload', {
    method: 'POST',
    headers: { 'Content-Type': 'multipart/form-data' },
    body: formData,
  });
}

export function toggleUser(id) {
  return request(`/admin/users/${id}/toggle`, { method: 'PUT' });
}

export function createUser(data) {
  return request('/admin/users', { method: 'POST', body: JSON.stringify(data) });
}
