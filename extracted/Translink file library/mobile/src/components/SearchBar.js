import React, { useState } from 'react';
import { View, TextInput, StyleSheet, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

export default function SearchBar({ onSearch, placeholder = 'Search files...' }) {
  const [query, setQuery] = useState('');

  function handleSubmit() {
    if (query.trim()) onSearch(query.trim());
  }

  return (
    <View style={styles.container}>
      <Ionicons name="search" size={20} color="#999" style={styles.icon} />
      <TextInput
        style={styles.input}
        placeholder={placeholder}
        placeholderTextColor="#999"
        value={query}
        onChangeText={setQuery}
        onSubmitEditing={handleSubmit}
        returnKeyType="search"
      />
      {query.length > 0 && (
        <TouchableOpacity onPress={() => { setQuery(''); onSearch(''); }}>
          <Ionicons name="close-circle" size={20} color="#bbb" />
        </TouchableOpacity>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#f0f2f5',
    borderRadius: 12,
    paddingHorizontal: 12,
    height: 44,
    marginHorizontal: 16,
    marginVertical: 8,
  },
  icon: { marginRight: 8 },
  input: { flex: 1, fontSize: 15, color: '#1a1a2e' },
});
