import React, { createContext, useState, useContext, useEffect } from 'react';
import * as SecureStore from 'expo-secure-store';
import { setToken, getMe, login as apiLogin } from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    restoreSession();
  }, []);

  async function restoreSession() {
    try {
      const token = await SecureStore.getItemAsync('auth_token');
      if (token) {
        setToken(token);
        const data = await getMe();
        setUser(data.user || data);
      }
    } catch { }
    setLoading(false);
  }

  async function login(username, password) {
    const data = await apiLogin(username, password);
    const token = data.token || data.access_token;
    if (token) {
      await SecureStore.setItemAsync('auth_token', token);
      setToken(token);
      const me = await getMe();
      setUser(me.user || me);
    }
    return data;
  }

  async function logout() {
    await SecureStore.deleteItemAsync('auth_token');
    setToken(null);
    setUser(null);
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
