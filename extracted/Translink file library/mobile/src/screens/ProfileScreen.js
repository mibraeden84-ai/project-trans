import React, { useState } from 'react';
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Alert, ScrollView } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../contexts/AuthContext';
import { updateProfile } from '../api/client';

export default function ProfileScreen() {
  const { user, logout } = useAuth();
  const [email, setEmail] = useState(user?.email || '');
  const [saving, setSaving] = useState(false);

  async function handleSave() {
    setSaving(true);
    try {
      await updateProfile({ email });
      Alert.alert('Saved', 'Profile updated');
    } catch (e) {
      Alert.alert('Error', e.message);
    }
    setSaving(false);
  }

  const roleColors = { admin: '#dc2626', editor: '#7c3aed', user: '#1a73e8', viewer: '#059669' };

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20 }}>
      <View style={styles.avatar}>
        <Ionicons name="person" size={48} color="#fff" />
      </View>
      <Text style={styles.username}>{user?.username}</Text>
      <View style={[styles.roleBadge, { backgroundColor: (roleColors[user?.role] || '#888') + '18' }]}>
        <Text style={[styles.roleText, { color: roleColors[user?.role] || '#888' }]}>
          {user?.role?.toUpperCase()}
        </Text>
      </View>

      <View style={styles.section}>
        <Text style={styles.label}>Email</Text>
        <TextInput style={styles.input} value={email} onChangeText={setEmail} placeholder="your@email.com" placeholderTextColor="#aaa" keyboardType="email-address" autoCapitalize="none" />
      </View>

      <TouchableOpacity style={styles.saveBtn} onPress={handleSave} disabled={saving}>
        <Text style={styles.saveBtnText}>{saving ? 'Saving...' : 'Update Profile'}</Text>
      </TouchableOpacity>

      <View style={styles.infoSection}>
        <InfoRow label="Role" value={user?.role} />
        <InfoRow label="Member since" value={user?.created_at ? new Date(user.created_at).toLocaleDateString() : '—'} />
        <InfoRow label="Downloads" value={String(user?.total_downloads ?? '0')} />
        <InfoRow label="Active time" value={user?.total_active_seconds ? `${Math.round(user.total_active_seconds / 3600)}h` : '—'} />
      </View>

      <TouchableOpacity style={styles.logoutBtn} onPress={logout}>
        <Ionicons name="log-out-outline" size={20} color="#dc2626" />
        <Text style={styles.logoutText}>Sign Out</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

function InfoRow({ label, value }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  avatar: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: '#1a73e8',
    justifyContent: 'center',
    alignItems: 'center',
    alignSelf: 'center',
    marginBottom: 12,
    elevation: 4,
  },
  username: { fontSize: 22, fontWeight: '800', color: '#1a1a2e', textAlign: 'center' },
  roleBadge: { alignSelf: 'center', paddingHorizontal: 16, paddingVertical: 4, borderRadius: 8, marginTop: 6, marginBottom: 24 },
  roleText: { fontSize: 12, fontWeight: '700', letterSpacing: 1 },
  section: { marginBottom: 16 },
  label: { fontSize: 13, fontWeight: '600', color: '#666', marginBottom: 6 },
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    paddingHorizontal: 14,
    height: 46,
    fontSize: 15,
    color: '#1a1a2e',
    elevation: 1,
  },
  saveBtn: {
    backgroundColor: '#1a73e8',
    borderRadius: 10,
    height: 46,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 24,
  },
  saveBtnText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  infoSection: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    elevation: 1,
    marginBottom: 24,
  },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#f0f0f0',
  },
  infoLabel: { fontSize: 14, color: '#888' },
  infoValue: { fontSize: 14, fontWeight: '600', color: '#1a1a2e' },
  logoutBtn: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: 8,
    paddingVertical: 14,
    backgroundColor: '#fef2f2',
    borderRadius: 10,
  },
  logoutText: { color: '#dc2626', fontWeight: '600', fontSize: 15 },
});
