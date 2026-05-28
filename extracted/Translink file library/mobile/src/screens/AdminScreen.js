import React, { useState, useEffect } from 'react';
import { View, Text, FlatList, TouchableOpacity, StyleSheet, Alert } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { getAdminUsers, getAdminActivity, toggleUser, getAdminHealth } from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';
import { useAuth } from '../contexts/AuthContext';

const TABS = [
  { key: 'users', label: 'Users', icon: 'people' },
  { key: 'activity', label: 'Activity', icon: 'time' },
  { key: 'health', label: 'Health', icon: 'pulse' },
];

export default function AdminScreen() {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState('users');
  const [users, setUsers] = useState([]);
  const [activity, setActivity] = useState([]);
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => { loadTab(); }, [activeTab]);

  async function loadTab() {
    setLoading(true);
    try {
      if (activeTab === 'users') {
        const d = await getAdminUsers();
        setUsers(d.users || d.data || d || []);
      } else if (activeTab === 'activity') {
        const d = await getAdminActivity();
        setActivity(d.activity || d.data || d || []);
      } else if (activeTab === 'health') {
        const d = await getAdminHealth();
        setHealth(d);
      }
    } catch { }
    setLoading(false);
  }

  async function handleToggle(id) {
    try {
      await toggleUser(id);
      loadTab();
    } catch (e) { Alert.alert('Error', e.message); }
  }

  if (user?.role !== 'admin') {
    return (
      <View style={styles.centered}>
        <Ionicons name="shield-checkmark" size={48} color="#dc2626" />
        <Text style={styles.noAccess}>Admin access required</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Admin Dashboard</Text>

      <View style={styles.tabs}>
        {TABS.map(tab => (
          <TouchableOpacity key={tab.key} style={[styles.tab, activeTab === tab.key && styles.activeTab]} onPress={() => setActiveTab(tab.key)}>
            <Ionicons name={tab.icon} size={16} color={activeTab === tab.key ? '#1a73e8' : '#888'} />
            <Text style={[styles.tabText, activeTab === tab.key && styles.activeTabText]}>{tab.label}</Text>
          </TouchableOpacity>
        ))}
      </View>

      {loading ? <LoadingSpinner /> : activeTab === 'health' && health ? (
        <View style={styles.healthContainer}>
          {Object.entries(health.checks || health).map(([key, val]) => (
            <View key={key} style={styles.healthRow}>
              <Text style={styles.healthLabel}>{key}</Text>
              <Ionicons name={val?.ok ? 'checkmark-circle' : 'close-circle'} size={20} color={val?.ok ? '#16a34a' : '#dc2626'} />
            </View>
          ))}
        </View>
      ) : activeTab === 'users' ? (
        <FlatList
          data={users}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => (
            <View style={styles.userCard}>
              <View style={styles.userInfo}>
                <Text style={styles.userName}>{item.username}</Text>
                <View style={styles.userMeta}>
                  <View style={[styles.roleBadge, { backgroundColor: (item.role === 'admin' ? '#dc2626' : '#1a73e8') + '18' }]}>
                    <Text style={[styles.roleText, { color: item.role === 'admin' ? '#dc2626' : '#1a73e8' }]}>{item.role}</Text>
                  </View>
                  <View style={[styles.statusDot, { backgroundColor: item.is_active ? '#16a34a' : '#999' }]} />
                  <Text style={styles.statusText}>{item.is_active ? 'Active' : 'Inactive'}</Text>
                </View>
              </View>
              <TouchableOpacity style={styles.toggleBtn} onPress={() => handleToggle(item.id)}>
                <Text style={styles.toggleBtnText}>{item.is_active ? 'Deactivate' : 'Activate'}</Text>
              </TouchableOpacity>
            </View>
          )}
          contentContainerStyle={{ padding: 16 }}
        />
      ) : (
        <FlatList
          data={activity}
          keyExtractor={(item, i) => String(item.id || i)}
          renderItem={({ item }) => (
            <View style={styles.activityItem}>
              <Text style={styles.activityAction}>{item.action}</Text>
              <Text style={styles.activityDetail}>{item.entity_type} {item.entity_name}</Text>
              <Text style={styles.activityTime}>{item.created_at ? new Date(item.created_at).toLocaleString() : ''}</Text>
            </View>
          )}
          contentContainerStyle={{ padding: 16 }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 12 },
  noAccess: { fontSize: 16, color: '#666', fontWeight: '600' },
  title: { fontSize: 22, fontWeight: '800', color: '#1a1a2e', padding: 16, paddingBottom: 0 },
  tabs: {
    flexDirection: 'row',
    marginHorizontal: 16,
    marginVertical: 12,
    backgroundColor: '#e8ecf0',
    borderRadius: 10,
    padding: 3,
  },
  tab: {
    flex: 1,
    flexDirection: 'row',
    paddingVertical: 8,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
  },
  activeTab: { backgroundColor: '#fff', elevation: 2 },
  tabText: { fontSize: 13, fontWeight: '600', color: '#888' },
  activeTabText: { color: '#1a73e8' },
  userCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    elevation: 1,
  },
  userInfo: { flex: 1 },
  userName: { fontSize: 15, fontWeight: '700', color: '#1a1a2e', marginBottom: 4 },
  userMeta: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  roleBadge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 4 },
  roleText: { fontSize: 11, fontWeight: '700' },
  statusDot: { width: 8, height: 8, borderRadius: 4 },
  statusText: { fontSize: 12, color: '#666' },
  toggleBtn: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 6,
    backgroundColor: '#fef2f2',
  },
  toggleBtnText: { color: '#dc2626', fontSize: 12, fontWeight: '600' },
  activityItem: {
    backgroundColor: '#fff',
    borderRadius: 8,
    padding: 12,
    marginBottom: 6,
  },
  activityAction: { fontSize: 14, fontWeight: '600', color: '#1a1a2e' },
  activityDetail: { fontSize: 12, color: '#666', marginTop: 2 },
  activityTime: { fontSize: 11, color: '#aaa', marginTop: 4 },
  healthContainer: { padding: 16, backgroundColor: '#fff', borderRadius: 12, margin: 16, elevation: 1 },
  healthRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#f0f0f0',
  },
  healthLabel: { fontSize: 14, fontWeight: '600', color: '#1a1a2e', textTransform: 'capitalize' },
});
