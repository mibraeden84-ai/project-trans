import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

const ICON_MAP = { GPS: 'locate', GEO: 'globe', STAR: 'star', CAM: 'videocam' };
const DEFAULT_COLOR = '#1a73e8';

export default function BrandCard({ brand, onPress }) {
  const icon = ICON_MAP[brand.icon] || 'hardware-chip';
  return (
    <TouchableOpacity style={[styles.card, { borderLeftColor: brand.color || DEFAULT_COLOR }]} onPress={onPress} activeOpacity={0.7}>
      <View style={[styles.iconBox, { backgroundColor: (brand.color || DEFAULT_COLOR) + '20' }]}>
        <Ionicons name={icon} size={28} color={brand.color || DEFAULT_COLOR} />
      </View>
      <View style={styles.info}>
        <Text style={styles.name}>{brand.name}</Text>
        {brand.description && <Text style={styles.desc} numberOfLines={2}>{brand.description}</Text>}
      </View>
      <Ionicons name="chevron-forward" size={20} color="#999" />
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    marginHorizontal: 16,
    marginVertical: 5,
    borderLeftWidth: 4,
    elevation: 2,
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowOffset: { width: 0, height: 2 },
    shadowRadius: 8,
  },
  iconBox: {
    width: 50,
    height: 50,
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 14,
  },
  info: { flex: 1 },
  name: { fontSize: 17, fontWeight: '700', color: '#1a1a2e', marginBottom: 3 },
  desc: { fontSize: 13, color: '#666', lineHeight: 18 },
});
