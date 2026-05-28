import React, { useState, useEffect } from 'react';
import { View, Text, FlatList, StyleSheet, Alert } from 'react-native';
import { getFiles, downloadFile } from '../api/client';
import FileCard from '../components/FileCard';
import LoadingSpinner from '../components/LoadingSpinner';
import * as FileSystem from 'expo-file-system';
import * as Sharing from 'expo-sharing';

const TABS = [
  { key: 'config_files', label: 'Configs', icon: 'settings' },
  { key: 'firmware_files', label: 'Firmware', icon: 'flash' },
  { key: 'software_files', label: 'Software', icon: 'desktop' },
  { key: 'manuals', label: 'Manuals', icon: 'document-text' },
];

export default function ModelDetailScreen({ route, navigation }) {
  const { model, brand } = route.params;
  const [activeTab, setActiveTab] = useState('config_files');
  const [files, setFiles] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    navigation.setOptions({ title: model.name });
    loadFiles();
  }, [activeTab]);

  async function loadFiles() {
    setLoading(true);
    try {
      const data = await getFiles(activeTab, { model_id: model.id });
      setFiles(data.files || data.data || data || []);
    } catch { setFiles([]); }
    setLoading(false);
  }

  async function handleDownload(file) {
    try {
      const data = await downloadFile(activeTab, file.id);
      const url = data.url || data.download_url;
      if (url) {
        const ext = url.split('.').pop() || 'bin';
        const localUri = FileSystem.documentDirectory + file.name;
        const dl = await FileSystem.downloadAsync(url, localUri);
        if (await Sharing.isAvailableAsync()) {
          await Sharing.shareAsync(dl.uri);
        } else {
          Alert.alert('Downloaded', `File saved to: ${dl.uri}`);
        }
      }
    } catch (e) {
      Alert.alert('Download Error', e.message);
    }
  }

  return (
    <View style={styles.container}>
      <View style={styles.modelHeader}>
        <Text style={styles.modelName}>{model.name}</Text>
        {model.system_type && (
          <View style={[styles.badge, { backgroundColor: model.system_type === 'advanced' ? '#e8f4fd' : '#f0fdf4' }]}>
            <Text style={[styles.badgeText, { color: model.system_type === 'advanced' ? '#005aa0' : '#166534' }]}>
              {model.system_type}
            </Text>
          </View>
        )}
      </View>

      <View style={styles.tabs}>
        {TABS.map((tab) => (
          <View
            key={tab.key}
            style={[styles.tab, activeTab === tab.key && styles.activeTab]}
          >
            <Text
              style={[styles.tabText, activeTab === tab.key && styles.activeTabText]}
              onPress={() => setActiveTab(tab.key)}
            >
              {tab.label}
            </Text>
          </View>
        ))}
      </View>

      {loading ? (
        <LoadingSpinner />
      ) : files.length === 0 ? (
        <View style={styles.empty}>
          <Text style={styles.emptyText}>No {activeTab.replace('_', ' ')} found</Text>
        </View>
      ) : (
        <FlatList
          data={files}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => (
            <FileCard file={item} type={activeTab} onDownload={() => handleDownload(item)} />
          )}
          contentContainerStyle={{ padding: 16, paddingBottom: 30 }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  modelHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 4,
  },
  modelName: { fontSize: 20, fontWeight: '800', color: '#1a1a2e' },
  badge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 6 },
  badgeText: { fontSize: 12, fontWeight: '600', textTransform: 'capitalize' },
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
    paddingVertical: 8,
    borderRadius: 8,
    alignItems: 'center',
  },
  activeTab: { backgroundColor: '#fff', elevation: 2 },
  tabText: { fontSize: 13, fontWeight: '600', color: '#888' },
  activeTabText: { color: '#1a73e8' },
  empty: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  emptyText: { fontSize: 15, color: '#999' },
});
