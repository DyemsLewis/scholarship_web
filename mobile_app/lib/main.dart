import 'package:flutter/material.dart';

import 'screens/auth_screen.dart';
import 'screens/home_screen.dart';
import 'services/api_client.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final apiClient = ApiClient();
  await apiClient.init();

  runApp(ScholarshipMobileApp(apiClient: apiClient));
}

class ScholarshipMobileApp extends StatefulWidget {
  const ScholarshipMobileApp({required this.apiClient, super.key});

  final ApiClient apiClient;

  @override
  State<ScholarshipMobileApp> createState() => _ScholarshipMobileAppState();
}

class _ScholarshipMobileAppState extends State<ScholarshipMobileApp> {
  late bool isSignedIn;

  @override
  void initState() {
    super.initState();
    isSignedIn = widget.apiClient.hasToken;
  }

  void handleAuthenticated() {
    setState(() {
      isSignedIn = true;
    });
  }

  void handleLoggedOut() {
    setState(() {
      isSignedIn = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Scholarship Portal',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF081426),
          brightness: Brightness.light,
        ),
        scaffoldBackgroundColor: const Color(0xFFE8EFF8),
        useMaterial3: true,
      ),
      home: isSignedIn
          ? HomeScreen(
              apiClient: widget.apiClient,
              onLoggedOut: handleLoggedOut,
            )
          : AuthScreen(
              apiClient: widget.apiClient,
              onAuthenticated: handleAuthenticated,
            ),
    );
  }
}
