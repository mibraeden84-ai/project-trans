import React, { useState, useEffect } from 'react';
import { View, Text, FlatList, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { getModels } from '../api/client';
import ModelCard from '../components/ModelCard';
import LoadingSpinner from '../components/LoadingSpinner';

export default function BrandDetailScreen({ route, navigation }) {
  const { brand } = route.params;
  const [models, setModels] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    navigation.setOptions({ title: brand.name });
    loadModels();
  }, []);

  async function loadModels() {
    try {
      const data = await getModels(brand.slug);
      setModels(data.models || data.data || data || []);
    } catch { }
    setLoading(false);
  }

  const iconMap = { GPS: 'locate', GEO: 'globe', STAR: 'star', CAM: 'videocam' };
  const icon = iconMap[brand.icon] || 'hardware-chip';
  const color = brand.color || '#1a73e8';

  if (loading) return <LoadingSpinner />;

  return (
    <FlatList
      style={styles.container}
      data={models}
      keyExtractor={(item) => String(item.id)}
      renderItem={({ item }) => (
        <ModelCard
          model={item}
          onPress={() => navigation.navigate('ModelDetail', { model: item, brand })}
        />
      )}
      ListHeaderComponent={
        <View style={styles.header}>
          <View style={[styles.brandIcon, { backgroundColor: color + '18' }]}>
            <Ionicons name={icon} size={36} color={color} />
          </View>
          <Text style={styles.brandName}>{brand.name}</Text>
          {brand.description && <Text style={styles.desc}>{brand.description}</Text>}
          <Text style={styles.sectionTitle}>Device Models ({models.length})</Text>
        </View>
      }
      contentContainerStyle={{ padding: 16, paddingBottom: 30 }}
    />
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  header: { alignItems: 'center', paddingBottom: 12 },
  brandIcon: {
    width: 72,
    height: 72,
    borderRadius: 20,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 12,
  },
  brandName: { fontSize: 24, fontWeight: '800', color: '#1a1a2e' },
  desc: { fontSize: 13, color: '#666', textAlign: 'center', marginTop: 6, lineHeight: 18, paddingHorizontal: 20 },
  sectionTitle: { fontSize: 17, fontWeight: '700', color: '#1a1a2e', marginTop: 24, marginBottom: 8, alignSelf: 'flex-start' },
});
