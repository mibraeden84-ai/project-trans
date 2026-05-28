import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../contexts/AuthContext';

import LoginScreen from '../screens/LoginScreen';
import HomeScreen from '../screens/HomeScreen';
import SearchScreen from '../screens/SearchScreen';
import ProfileScreen from '../screens/ProfileScreen';
import AdminScreen from '../screens/AdminScreen';
import UploadScreen from '../screens/UploadScreen';
import BrandDetailScreen from '../screens/BrandDetailScreen';
import ModelDetailScreen from '../screens/ModelDetailScreen';

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();
const HomeStack = createNativeStackNavigator();
const SearchStack = createNativeStackNavigator();
const AdminStack = createNativeStackNavigator();
const UploadStack = createNativeStackNavigator();

function HomeStackScreen() {
  return (
    <HomeStack.Navigator screenOptions={{ headerStyle: { backgroundColor: '#f5f6fa' }, headerTintColor: '#1a1a2e', headerTitleStyle: { fontWeight: '700' } }}>
      <HomeStack.Screen name="Home" component={HomeScreen} options={{ headerShown: false }} />
      <HomeStack.Screen name="BrandDetail" component={BrandDetailScreen} />
      <HomeStack.Screen name="ModelDetail" component={ModelDetailScreen} />
    </HomeStack.Navigator>
  );
}

function SearchStackScreen() {
  return (
    <SearchStack.Navigator screenOptions={{ headerStyle: { backgroundColor: '#f5f6fa' }, headerTintColor: '#1a1a2e', headerTitleStyle: { fontWeight: '700' } }}>
      <SearchStack.Screen name="Search" component={SearchScreen} options={{ title: 'Search Files' }} />
    </SearchStack.Navigator>
  );
}

function AdminStackScreen() {
  return (
    <AdminStack.Navigator screenOptions={{ headerStyle: { backgroundColor: '#f5f6fa' }, headerTintColor: '#1a1a2e', headerTitleStyle: { fontWeight: '700' } }}>
      <AdminStack.Screen name="Admin" component={AdminScreen} options={{ headerShown: false }} />
    </AdminStack.Navigator>
  );
}

function UploadStackScreen() {
  return (
    <UploadStack.Navigator screenOptions={{ headerStyle: { backgroundColor: '#f5f6fa' }, headerTintColor: '#1a1a2e', headerTitleStyle: { fontWeight: '700' } }}>
      <UploadStack.Screen name="Upload" component={UploadScreen} options={{ headerShown: false }} />
    </UploadStack.Navigator>
  );
}

function MainTabs() {
  const { user } = useAuth();
  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        tabBarIcon: ({ focused, color, size }) => {
          const icons = {
            HomeTab: focused ? 'home' : 'home-outline',
            SearchTab: focused ? 'search' : 'search-outline',
            UploadTab: focused ? 'cloud-upload' : 'cloud-upload-outline',
            AdminTab: focused ? 'shield' : 'shield-outline',
            ProfileTab: focused ? 'person' : 'person-outline',
          };
          return <Ionicons name={icons[route.name]} size={size} color={color} />;
        },
        tabBarActiveTintColor: '#1a73e8',
        tabBarInactiveTintColor: '#999',
        tabBarStyle: {
          backgroundColor: '#fff',
          borderTopWidth: 0,
          elevation: 8,
          height: 60,
          paddingBottom: 8,
          paddingTop: 4,
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
        headerShown: false,
      })}
    >
      <Tab.Screen name="HomeTab" component={HomeStackScreen} options={{ tabBarLabel: 'Home' }} />
      <Tab.Screen name="SearchTab" component={SearchStackScreen} options={{ tabBarLabel: 'Search' }} />
      <Tab.Screen name="UploadTab" component={UploadStackScreen} options={{ tabBarLabel: 'Upload' }} />
      {user?.role === 'admin' && (
        <Tab.Screen name="AdminTab" component={AdminStackScreen} options={{ tabBarLabel: 'Admin' }} />
      )}
      <Tab.Screen name="ProfileTab" component={ProfileScreen} options={{ tabBarLabel: 'Profile' }} />
    </Tab.Navigator>
  );
}

export default function AppNavigator() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#0f0f23' }}>
        <ActivityIndicator size="large" color="#1a73e8" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        {user ? (
          <Stack.Screen name="Main" component={MainTabs} />
        ) : (
          <Stack.Screen name="Login" component={LoginScreen} />
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
}
