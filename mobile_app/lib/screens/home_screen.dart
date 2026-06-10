import 'package:flutter/material.dart';

import '../services/api_client.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({
    required this.apiClient,
    required this.onLoggedOut,
    super.key,
  });

  final ApiClient apiClient;
  final VoidCallback onLoggedOut;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  bool isLoading = true;
  String? errorMessage;
  Map<String, dynamic>? user;
  Map<String, dynamic> stats = {};
  List<dynamic> nextSteps = [];

  @override
  void initState() {
    super.initState();
    loadProfile();
  }

  Future<void> loadProfile() async {
    setState(() {
      isLoading = true;
      errorMessage = null;
    });

    try {
      final data = await widget.apiClient.profile();
      setState(() {
        user = data['user'] as Map<String, dynamic>;
        stats = data['stats'] as Map<String, dynamic>;
        nextSteps = data['next_steps'] as List<dynamic>;
      });
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.apiClient.clearToken();
        widget.onLoggedOut();
        return;
      }

      setState(() {
        errorMessage = error.message;
      });
    } catch (_) {
      setState(() {
        errorMessage = 'Unable to load your applicant dashboard.';
      });
    } finally {
      if (mounted) {
        setState(() {
          isLoading = false;
        });
      }
    }
  }

  Future<void> logout() async {
    await widget.apiClient.logout();
    widget.onLoggedOut();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFF081426), Color(0xFFE8EFF8)],
            stops: [0, 0.38],
          ),
        ),
        child: SafeArea(
          child: RefreshIndicator(
            onRefresh: loadProfile,
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                _Header(
                  name: user?['first_name'] as String? ?? 'Applicant',
                  onLogout: logout,
                ),
                const SizedBox(height: 18),
                if (isLoading)
                  const _LoadingCard()
                else if (errorMessage != null)
                  _ErrorCard(message: errorMessage!, onRetry: loadProfile)
                else ...[
                  _ProfileCard(user: user ?? {}),
                  const SizedBox(height: 16),
                  _StatsGrid(stats: stats),
                  const SizedBox(height: 16),
                  _NextStepsCard(steps: nextSteps),
                  const SizedBox(height: 16),
                  const _OpportunityCard(),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.name, required this.onLogout});

  final String name;
  final VoidCallback onLogout;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'APPLICANT PORTAL',
                style: TextStyle(
                  color: Color(0xFFFDE68A),
                  fontSize: 12,
                  fontWeight: FontWeight.w900,
                  letterSpacing: 2.2,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                'Welcome, $name',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 28,
                  fontWeight: FontWeight.w900,
                  height: 1.1,
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Track your scholarship profile and future applications.',
                style: TextStyle(color: Color(0xFFE2E8F0), fontSize: 15),
              ),
            ],
          ),
        ),
        IconButton.filledTonal(
          onPressed: onLogout,
          icon: const Icon(Icons.logout),
          tooltip: 'Logout',
        ),
      ],
    );
  }
}

class _LoadingCard extends StatelessWidget {
  const _LoadingCard();

  @override
  Widget build(BuildContext context) {
    return const Card(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      ),
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Card(
      color: const Color(0xFFFFF1F2),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              message,
              style: const TextStyle(
                color: Color(0xFFBE123C),
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ),
      ),
    );
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.user});

  final Map<String, dynamic> user;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 12,
      shadowColor: Colors.black12,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Applicant Profile',
              style: TextStyle(
                color: Color(0xFF0369A1),
                fontWeight: FontWeight.w900,
                letterSpacing: 1.6,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              user['name'] as String? ?? '',
              style: const TextStyle(
                color: Color(0xFF020617),
                fontSize: 22,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 6),
            Text(user['email'] as String? ?? ''),
            const SizedBox(height: 4),
            Text(user['contact_number'] as String? ?? 'No contact number'),
          ],
        ),
      ),
    );
  }
}

class _StatsGrid extends StatelessWidget {
  const _StatsGrid({required this.stats});

  final Map<String, dynamic> stats;

  @override
  Widget build(BuildContext context) {
    final cards = [
      _StatData('Available', stats['available_scholarships'] ?? 0, Colors.blue),
      _StatData('Applications', stats['applications'] ?? 0, Colors.green),
      _StatData('Saved', stats['saved'] ?? 0, Colors.amber),
    ];

    return Row(
      children: cards
          .map(
            (card) => Expanded(
              child: Padding(
                padding: EdgeInsets.only(right: card == cards.last ? 0 : 10),
                child: _StatCard(data: card),
              ),
            ),
          )
          .toList(),
    );
  }
}

class _StatData {
  const _StatData(this.label, this.value, this.color);

  final String label;
  final Object value;
  final MaterialColor color;
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.data});

  final _StatData data;

  @override
  Widget build(BuildContext context) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              data.label,
              style: const TextStyle(
                color: Color(0xFF64748B),
                fontWeight: FontWeight.w800,
                fontSize: 12,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              '${data.value}',
              style: TextStyle(
                color: data.color.shade700,
                fontSize: 26,
                fontWeight: FontWeight.w900,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NextStepsCard extends StatelessWidget {
  const _NextStepsCard({required this.steps});

  final List<dynamic> steps;

  @override
  Widget build(BuildContext context) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Next Steps',
              style: TextStyle(
                color: Color(0xFF020617),
                fontSize: 18,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 12),
            for (final step in steps)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Padding(
                      padding: EdgeInsets.only(top: 5),
                      child: Icon(
                        Icons.circle,
                        color: Color(0xFFFACC15),
                        size: 9,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(child: Text('$step')),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _OpportunityCard extends StatelessWidget {
  const _OpportunityCard();

  @override
  Widget build(BuildContext context) {
    return Card(
      color: const Color(0xFF081426),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: const Padding(
        padding: EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Scholarship Listings',
              style: TextStyle(
                color: Color(0xFFFDE68A),
                fontSize: 13,
                fontWeight: FontWeight.w900,
                letterSpacing: 1.6,
              ),
            ),
            SizedBox(height: 10),
            Text(
              'Listings will appear here once providers publish scholarship programs on the web portal.',
              style: TextStyle(color: Colors.white, height: 1.55),
            ),
          ],
        ),
      ),
    );
  }
}
