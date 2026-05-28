import React, { useState } from 'react';
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Alert, KeyboardAvoidingView, Platform } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../contexts/AuthContext';

export default function LoginScreen() {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPw, setShowPw] = useState(false);
  const [loading, setLoading] = useState(false);

  async function handleLogin() {
    if (!username.trim() || !password.trim()) {
      Alert.alert('Error', 'Please enter username and password');
      return;
    }
    setLoading(true);
    try {
      await login(username.trim(), password);
    } catch (e) {
      Alert.alert('Login Failed', e.message || 'Invalid credentials');
    }
    setLoading(false);
  }

  return (
    <KeyboardAvoidingView style={styles.container} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.topSection}>
        <View style={styles.logoCircle}>
          <Ionicons name="locate" size={40} color="#fff" />
        </View>
        <Text style={styles.title}>Translink GPS</Text>
        <Text style={styles.subtitle}>File Library</Text>
      </View>

      <View style={styles.form}>
        <View style={styles.inputGroup}>
          <Ionicons name="person-outline" size={20} color="#888" style={styles.inputIcon} />
          <TextInput
            style={styles.input}
            placeholder="Username"
            placeholderTextColor="#aaa"
            value={username}
            onChangeText={setUsername}
            autoCapitalize="none"
            autoCorrect={false}
          />
        </View>

        <View style={styles.inputGroup}>
          <Ionicons name="lock-closed-outline" size={20} color="#888" style={styles.inputIcon} />
          <TextInput
            style={styles.input}
            placeholder="Password"
            placeholderTextColor="#aaa"
            value={password}
            onChangeText={setPassword}
            secureTextEntry={!showPw}
          />
          <TouchableOpacity onPress={() => setShowPw(!showPw)}>
            <Ionicons name={showPw ? 'eye-off-outline' : 'eye-outline'} size={20} color="#888" />
          </TouchableOpacity>
        </View>

        <TouchableOpacity style={[styles.loginBtn, loading && { opacity: 0.7 }]} onPress={handleLogin} disabled={loading}>
          <Text style={styles.loginBtnText}>{loading ? 'Signing in...' : 'Sign In'}</Text>
        </TouchableOpacity>

        <TouchableOpacity style={styles.forgotBtn}>
          <Text style={styles.forgotText}>Forgot password?</Text>
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f0f23' },
  topSection: {
    flex: 0.4,
    justifyContent: 'center',
    alignItems: 'center',
    paddingTop: 60,
  },
  logoCircle: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: '#1a73e8',
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 16,
    elevation: 8,
  },
  title: { fontSize: 28, fontWeight: '800', color: '#fff' },
  subtitle: { fontSize: 16, color: '#888', marginTop: 4 },
  form: {
    flex: 0.6,
    backgroundColor: '#fff',
    borderTopLeftRadius: 30,
    borderTopRightRadius: 30,
    paddingHorizontal: 30,
    paddingTop: 40,
  },
  inputGroup: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#f5f6fa',
    borderRadius: 12,
    paddingHorizontal: 14,
    height: 50,
    marginBottom: 16,
  },
  inputIcon: { marginRight: 10 },
  input: { flex: 1, fontSize: 15, color: '#1a1a2e' },
  loginBtn: {
    backgroundColor: '#1a73e8',
    borderRadius: 12,
    height: 50,
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 8,
    elevation: 3,
  },
  loginBtnText: { color: '#fff', fontSize: 16, fontWeight: '700' },
  forgotBtn: { alignItems: 'center', marginTop: 16 },
  forgotText: { color: '#1a73e8', fontSize: 14 },
});
