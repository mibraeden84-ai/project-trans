import React, { useState, useEffect, useCallback } from 'react';
import { View, Text, FlatList, StyleSheet, RefreshControl } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { getBrands, getStats } from '../api/client';
import BrandCard from '../components/BrandCard';
import LoadingSpinner from '../components/LoadingSpinner';

export default function HomeScreen({ navigation }) {
  const [brands, setBrands] = useState([]);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  useFocusEffect(useCallback(() => { loadData(); }, []));

  async function loadData() {
    try {
      const [b, s] = await Promise.all([getBrands(), getStats()]);
      setBrands(b.brands || b.data || b || []);
      setStats(s);
    } catch { }
    setLoading(false);
    setRefreshing(false);
  }

  function onRefresh() { setRefreshing(true); loadData(); }

  if (loading) return <LoadingSpinner />;

  return (
    <FlatList
      style={styles.container}
      data={brands}
      keyExtractor={(item) => String(item.id)}
      renderItem={({ item }) => <BrandCard brand={item} onPress={() => navigation.navigate('BrandDetail', { brand: item })} />}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#1a73e8" />}
      ListHeaderComponent={
        <View>
          <View style={styles.header}>
            <Text style={styles.greeting}>Translink GPS</Text>
            <Text style={styles.headline}>File Library</Text>
          </View>
          {stats && (
            <View style={styles.statsRow}>
              <StatBox label="Brands" value={brands.length} />
              <StatBox label="Models" value={stats.model_count || stats.models || '—'} />
              <StatBox label="Files" value={stats.file_count || stats.files || '—'} />
              <StatBox label="Downloads" value={stats.download_count || stats.downloads || '—'} />
            </View>
          )}
          <Text style={styles.sectionTitle}>Brands</Text>
        </View>
      }
      contentContainerStyle={{ paddingBottom: 20 }}
    />
  );
}

function StatBox({ label, value }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statValue}>{value ?? '—'}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  header: { paddingHorizontal: 20, paddingTop: 20, paddingBottom: 8 },
  greeting: { fontSize: 14, color: '#888', fontWeight: '500' },
  headline: { fontSize: 26, fontWeight: '800', color: '#1a1a2e', marginTop: 2 },
  statsRow: {
    flexDirection: 'row',
    marginHorizontal: 16,
    marginVertical: 12,
    gap: 8,
  },
  stat: {
    flex: 1,
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 14,
    alignItems: 'center',
    elevation: 2,
    shadowColor: '#000',
    shadowOpacity: 0.06,
    shadowOffset: { width: 0, height: 2 },
    shadowRadius: 6,
  },
  statValue: { fontSize: 22, fontWeight: '800', color: '#1a73e8' },
  statLabel: { fontSize: 11, color: '#888', marginTop: 3, textTransform: 'uppercase', letterSpacing: 0.5 },
  sectionTitle: { fontSize: 18, fontWeight: '700', color: '#1a1a2e', marginHorizontal: 20, marginTop: 16, marginBottom: 8 },
});
