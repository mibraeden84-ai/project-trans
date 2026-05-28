import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

const TYPE_CONFIG = {
  config_files: { icon: 'settings', color: '#1a73e8', label: 'Config' },
  firmware_files: { icon: 'flash', color: '#e67e22', label: 'Firmware' },
  software_files: { icon: 'desktop', color: '#7c3aed', label: 'Software' },
  manuals: { icon: 'document-text', color: '#059669', label: 'Manual' },
};

function formatSize(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

export default function FileCard({ file, type, onPress, onDownload }) {
  const config = TYPE_CONFIG[type] || { icon: 'document', color: '#666', label: 'File' };
  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.7}>
      <View style={[styles.iconBox, { backgroundColor: config.color + '18' }]}>
        <Ionicons name={config.icon} size={22} color={config.color} />
      </View>
      <View style={styles.info}>
        <Text style={styles.name} numberOfLines={1}>{file.name}</Text>
        <View style={styles.meta}>
          {file.version && <Text style={styles.version}>v{file.version}</Text>}
          <Text style={styles.size}>{formatSize(file.file_size)}</Text>
          {file.download_count > 0 && (
            <Text style={styles.downloads}>{file.download_count} downloads</Text>
          )}
        </View>
      </View>
      {onDownload && (
        <TouchableOpacity style={styles.dlBtn} onPress={onDownload}>
          <Ionicons name="download-outline" size={22} color="#1a73e8" />
        </TouchableOpacity>
      )}
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    elevation: 1,
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowOffset: { width: 0, height: 1 },
    shadowRadius: 4,
  },
  iconBox: {
    width: 42,
    height: 42,
    borderRadius: 10,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  info: { flex: 1 },
  name: { fontSize: 14, fontWeight: '600', color: '#1a1a2e', marginBottom: 4 },
  meta: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  version: { fontSize: 12, color: '#1a73e8', fontWeight: '600' },
  size: { fontSize: 12, color: '#888' },
  downloads: { fontSize: 11, color: '#aaa' },
  dlBtn: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: '#e8f0fe',
    justifyContent: 'center',
    alignItems: 'center',
  },
});
