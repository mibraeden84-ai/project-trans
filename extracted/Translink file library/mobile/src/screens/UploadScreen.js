import React, { useState, useEffect } from 'react';
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Alert, ScrollView, Platform } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { getBrands, getModels, uploadFile } from '../api/client';
import * as DocumentPicker from 'expo-document-picker';

const FILE_TYPES = [
  { key: 'config', label: 'Config' },
  { key: 'firmware', label: 'Firmware' },
  { key: 'software', label: 'Software' },
  { key: 'manual', label: 'Manual' },
];

export default function UploadScreen() {
  const [brands, setBrands] = useState([]);
  const [models, setModels] = useState([]);
  const [selectedBrand, setSelectedBrand] = useState(null);
  const [selectedModel, setSelectedModel] = useState(null);
  const [fileType, setFileType] = useState('config');
  const [document, setDocument] = useState(null);
  const [version, setVersion] = useState('1.0');
  const [description, setDescription] = useState('');
  const [uploading, setUploading] = useState(false);

  useEffect(() => { getBrands().then(d => setBrands(d.brands || d.data || d || [])); }, []);

  async function selectBrand(brand) {
    setSelectedBrand(brand);
    setSelectedModel(null);
    setModels([]);
    if (brand) {
      const d = await getModels(brand.slug);
      setModels(d.models || d.data || d || []);
    }
  }

  async function pickDocument() {
    const result = await DocumentPicker.getDocumentAsync({ type: '*/*', copyToCacheDir: true });
    if (!result.canceled && result.assets?.length > 0) {
      setDocument(result.assets[0]);
    }
  }

  async function handleUpload() {
    if (!document) { Alert.alert('Error', 'Please select a file'); return; }
    if (!selectedBrand && fileType !== 'config') { Alert.alert('Error', 'Please select a brand'); return; }
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append('file', {
        uri: document.uri,
        name: document.name,
        type: document.mimeType || 'application/octet-stream',
      });
      formData.append('type', fileType);
      formData.append('version', version);
      formData.append('description', description);
      if (selectedBrand) formData.append('brand_id', String(selectedBrand.id));
      if (selectedModel) formData.append('model_id', String(selectedModel.id));

      await uploadFile(formData);
      Alert.alert('Success', 'File uploaded successfully');
      setDocument(null);
      setVersion('1.0');
      setDescription('');
    } catch (e) {
      Alert.alert('Upload Failed', e.message);
    }
    setUploading(false);
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={styles.title}>Upload File</Text>

      <Text style={styles.label}>File Type</Text>
      <View style={styles.chipRow}>
        {FILE_TYPES.map(t => (
          <TouchableOpacity key={t.key} style={[styles.chip, fileType === t.key && styles.chipActive]} onPress={() => setFileType(t.key)}>
            <Text style={[styles.chipText, fileType === t.key && styles.chipTextActive]}>{t.label}</Text>
          </TouchableOpacity>
        ))}
      </View>

      {fileType !== 'config' && (
        <>
          <Text style={styles.label}>Brand</Text>
          <View style={styles.chipRow}>
            {brands.map(b => (
              <TouchableOpacity key={b.id} style={[styles.chip, selectedBrand?.id === b.id && styles.chipActive]} onPress={() => selectBrand(b)}>
                <Text style={[styles.chipText, selectedBrand?.id === b.id && styles.chipTextActive]}>{b.name}</Text>
              </TouchableOpacity>
            ))}
          </View>
        </>
      )}

      {selectedBrand && models.length > 0 && (
        <>
          <Text style={styles.label}>Model (optional)</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.modelScroll}>
            <View style={styles.chipRow}>
              <TouchableOpacity style={[styles.chip, !selectedModel && styles.chipActive]} onPress={() => setSelectedModel(null)}>
                <Text style={[styles.chipText, !selectedModel && styles.chipTextActive]}>All</Text>
              </TouchableOpacity>
              {models.map(m => (
                <TouchableOpacity key={m.id} style={[styles.chip, selectedModel?.id === m.id && styles.chipActive]} onPress={() => setSelectedModel(m)}>
                  <Text style={[styles.chipText, selectedModel?.id === m.id && styles.chipTextActive]}>{m.name}</Text>
                </TouchableOpacity>
              ))}
            </View>
          </ScrollView>
        </>
      )}

      <Text style={styles.label}>Version</Text>
      <TextInput style={styles.input} value={version} onChangeText={setVersion} placeholder="1.0" />

      <Text style={styles.label}>Description</Text>
      <TextInput style={[styles.input, styles.textArea]} value={description} onChangeText={setDescription} placeholder="File description..." multiline numberOfLines={3} />

      <Text style={styles.label}>File</Text>
      <TouchableOpacity style={styles.filePicker} onPress={pickDocument}>
        <Ionicons name="cloud-upload-outline" size={28} color="#1a73e8" />
        <Text style={styles.filePickerText}>{document ? document.name : 'Tap to select file'}</Text>
      </TouchableOpacity>

      <TouchableOpacity style={[styles.uploadBtn, uploading && { opacity: 0.6 }]} onPress={handleUpload} disabled={uploading}>
        <Ionicons name="cloud-upload" size={20} color="#fff" />
        <Text style={styles.uploadBtnText}>{uploading ? 'Uploading...' : 'Upload File'}</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  title: { fontSize: 22, fontWeight: '800', color: '#1a1a2e', marginBottom: 20 },
  label: { fontSize: 13, fontWeight: '600', color: '#666', marginBottom: 6, marginTop: 12 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: {
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 8,
    backgroundColor: '#e8ecf0',
  },
  chipActive: { backgroundColor: '#1a73e8' },
  chipText: { fontSize: 13, fontWeight: '600', color: '#666' },
  chipTextActive: { color: '#fff' },
  modelScroll: { marginBottom: 4 },
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    paddingHorizontal: 14,
    height: 46,
    fontSize: 15,
    color: '#1a1a2e',
    elevation: 1,
  },
  textArea: { height: 80, paddingTop: 12, textAlignVertical: 'top' },
  filePicker: {
    backgroundColor: '#e8f0fe',
    borderRadius: 10,
    borderWidth: 2,
    borderColor: '#1a73e8',
    borderStyle: 'dashed',
    padding: 24,
    alignItems: 'center',
    gap: 8,
  },
  filePickerText: { color: '#1a73e8', fontSize: 14, fontWeight: '600' },
  uploadBtn: {
    backgroundColor: '#1a73e8',
    borderRadius: 10,
    height: 48,
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: 8,
    marginTop: 24,
  },
  uploadBtnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
});
