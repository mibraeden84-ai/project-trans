import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

export default function ModelCard({ model, onPress }) {
  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.7}>
      <View style={styles.iconBox}>
        <Ionicons name="hardware-chip" size={22} color="#1a73e8" />
      </View>
      <View style={styles.info}>
        <Text style={styles.name}>{model.name}</Text>
        {model.system_type && (
          <View style={[styles.badge, { backgroundColor: model.system_type === 'advanced' ? '#e8f4fd' : '#f0fdf4' }]}>
            <Text style={[styles.badgeText, { color: model.system_type === 'advanced' ? '#005aa0' : '#166534' }]}>
              {model.system_type}
            </Text>
          </View>
        )}
      </View>
      <Ionicons name="chevron-forward" size={18} color="#bbb" />
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#f8f9fa',
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
  },
  iconBox: {
    width: 40,
    height: 40,
    borderRadius: 10,
    backgroundColor: '#e8f0fe',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  info: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8 },
  name: { fontSize: 15, fontWeight: '600', color: '#1a1a2e' },
  badge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6 },
  badgeText: { fontSize: 11, fontWeight: '600', textTransform: 'capitalize' },
});
