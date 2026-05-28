import React, { useState } from 'react';
import { View, Text, FlatList, StyleSheet } from 'react-native';
import { searchFiles } from '../api/client';
import SearchBar from '../components/SearchBar';
import FileCard from '../components/FileCard';

export default function SearchScreen({ navigation }) {
  const [results, setResults] = useState([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading] = useState(false);

  async function handleSearch(query) {
    if (!query) { setResults([]); setSearched(false); return; }
    setLoading(true);
    setSearched(true);
    try {
      const data = await searchFiles(query);
      setResults(data.results || data.data || data || []);
    } catch { setResults([]); }
    setLoading(false);
  }

  return (
    <View style={styles.container}>
      <SearchBar onSearch={handleSearch} />
      {loading && <Text style={styles.status}>Searching...</Text>}
      {!loading && searched && results.length === 0 && (
        <View style={styles.empty}>
          <Text style={styles.emptyText}>No results found</Text>
        </View>
      )}
      <FlatList
        data={results}
        keyExtractor={(item, i) => String(item.id || i)}
        renderItem={({ item }) => (
          <FileCard file={item} type={item._type || 'config_files'} />
        )}
        contentContainerStyle={{ padding: 16 }}
        ListHeaderComponent={
          searched && results.length > 0 ? (
            <Text style={styles.count}>{results.length} result{results.length > 1 ? 's' : ''}</Text>
          ) : null
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f5f6fa' },
  status: { textAlign: 'center', color: '#888', marginTop: 20 },
  empty: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  emptyText: { fontSize: 15, color: '#999' },
  count: { fontSize: 13, color: '#888', marginBottom: 10 },
});
