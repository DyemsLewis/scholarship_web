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
  bool isSaving = false;
  String? errorMessage;
  int selectedTab = 0;
  Map<String, dynamic> user = {};
  Map<String, dynamic> stats = {};
  List<Map<String, dynamic>> scholarships = [];
  List<Map<String, dynamic>> applications = [];
  List<dynamic> nextSteps = [];
  String programSearch = '';
  String selectedCategory = 'all';
  String selectedProviderType = 'all';
  String selectedIncomeRule = 'all';
  String selectedDeadline = 'all';
  String minimumMatch = '';
  bool savedOnly = false;

  @override
  void initState() {
    super.initState();
    loadPortal();
  }

  Future<void> loadPortal() async {
    setState(() {
      isLoading = true;
      errorMessage = null;
    });

    try {
      final data = await widget.apiClient.profile();

      setState(() {
        user = asMap(data['user']);
        stats = asMap(data['stats']);
        scholarships = asMapList(data['scholarships']);
        applications = asMapList(data['applications']);
        nextSteps = data['next_steps'] is List ? data['next_steps'] as List : [];
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

  Future<void> toggleSave(Map<String, dynamic> scholarship) async {
    setState(() => isSaving = true);

    try {
      final id = intValue(scholarship['id']);
      if (scholarship['is_saved'] == true) {
        await widget.apiClient.unsaveScholarship(id);
      } else {
        await widget.apiClient.saveScholarship(id);
      }

      await loadPortal();
    } on ApiException catch (error) {
      showMessage(error.message);
    } finally {
      if (mounted) {
        setState(() => isSaving = false);
      }
    }
  }

  Future<void> openApplicationSheet(Map<String, dynamic> scholarship) async {
    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _ApplicationSheet(
        scholarship: scholarship,
        apiClient: widget.apiClient,
      ),
    );

    if (submitted == true) {
      showMessage('Application submitted successfully.');
      await loadPortal();
      setState(() => selectedTab = 2);
    }
  }

  Future<void> openProfileEditor() async {
    final updated = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _ProfileEditor(
        user: user,
        apiClient: widget.apiClient,
      ),
    );

    if (updated == true) {
      showMessage('Profile updated.');
      await loadPortal();
    }
  }

  void showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      bottomNavigationBar: NavigationBar(
        selectedIndex: selectedTab,
        onDestinationSelected: (index) => setState(() => selectedTab = index),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.dashboard_outlined), selectedIcon: Icon(Icons.dashboard), label: 'Home'),
          NavigationDestination(icon: Icon(Icons.school_outlined), selectedIcon: Icon(Icons.school), label: 'Programs'),
          NavigationDestination(icon: Icon(Icons.assignment_outlined), selectedIcon: Icon(Icons.assignment), label: 'Applications'),
          NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Profile'),
        ],
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFF081426), Color(0xFFE8EFF8)],
            stops: [0, 0.34],
          ),
        ),
        child: SafeArea(
          child: RefreshIndicator(
            onRefresh: loadPortal,
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                _Header(
                  name: stringValue(user['first_name'], fallback: 'Applicant'),
                  onLogout: logout,
                ),
                const SizedBox(height: 18),
                if (isLoading)
                  const _LoadingCard()
                else if (errorMessage != null)
                  _ErrorCard(message: errorMessage!, onRetry: loadPortal)
                else
                  ..._tabContent(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _tabContent() {
    if (selectedTab == 1) {
      final filteredPrograms = filterScholarships(
        scholarships: scholarships,
        search: programSearch,
        category: selectedCategory,
        providerType: selectedProviderType,
        incomeRule: selectedIncomeRule,
        deadline: selectedDeadline,
        minimumMatch: minimumMatch,
        savedOnly: savedOnly,
      );

      return [
        _PhotoBanner(
          imageUrl: '${ApiClient.assetBaseUrl}/images/scholarship-cards.jpg',
          title: 'Scholarship Programs',
          subtitle: 'Browse, save, and apply to published programs.',
        ),
        const SizedBox(height: 16),
        _FinderFiltersCard(
          total: scholarships.length,
          visible: filteredPrograms.length,
          search: programSearch,
          selectedCategory: selectedCategory,
          selectedProviderType: selectedProviderType,
          selectedIncomeRule: selectedIncomeRule,
          selectedDeadline: selectedDeadline,
          minimumMatch: minimumMatch,
          savedOnly: savedOnly,
          categories: filterOptions(scholarships, (scholarship) => stringValue(scholarship['category'])),
          providerTypes: filterOptions(scholarships, (scholarship) => stringValue(asMap(scholarship['provider'])['type'])),
          incomeRules: filterOptions(scholarships, (scholarship) => stringValue(scholarship['income_requirement'])),
          onSearchChanged: (value) => setState(() => programSearch = value),
          onCategoryChanged: (value) => setState(() => selectedCategory = value ?? 'all'),
          onProviderChanged: (value) => setState(() => selectedProviderType = value ?? 'all'),
          onIncomeChanged: (value) => setState(() => selectedIncomeRule = value ?? 'all'),
          onDeadlineChanged: (value) => setState(() => selectedDeadline = value ?? 'all'),
          onMinimumMatchChanged: (value) => setState(() => minimumMatch = value),
          onSavedOnlyChanged: (value) => setState(() => savedOnly = value ?? false),
          onReset: () => setState(() {
            programSearch = '';
            selectedCategory = 'all';
            selectedProviderType = 'all';
            selectedIncomeRule = 'all';
            selectedDeadline = 'all';
            minimumMatch = '';
            savedOnly = false;
          }),
        ),
        const SizedBox(height: 16),
        if (scholarships.isEmpty)
          const _EmptyCard(message: 'No published scholarship programs yet.')
        else if (filteredPrograms.isEmpty)
          const _EmptyCard(message: 'No scholarships match your current finder filters.')
        else
          for (final scholarship in filteredPrograms)
            _ScholarshipCard(
              scholarship: scholarship,
              isSaving: isSaving,
              alreadyApplied: applications.any((application) => intValue(asMap(application['scholarship'])['id']) == intValue(scholarship['id'])),
              onSave: () => toggleSave(scholarship),
              onApply: () => openApplicationSheet(scholarship),
            ),
      ];
    }

    if (selectedTab == 2) {
      return [
        _PhotoBanner(
          imageUrl: '${ApiClient.assetBaseUrl}/images/application-documents.jpg',
          title: 'Applications',
          subtitle: 'Track DSS guidance, provider review, and document status.',
        ),
        const SizedBox(height: 16),
        const _DssFormulaCard(),
        const SizedBox(height: 16),
        if (applications.isEmpty)
          const _EmptyCard(message: 'No applications submitted yet.')
        else
          for (final application in applications) _ApplicationCard(application: application),
      ];
    }

    if (selectedTab == 3) {
      return [
        _PhotoBanner(
          imageUrl: '${ApiClient.assetBaseUrl}/images/student-dashboard.jpg',
          title: 'Applicant Profile',
          subtitle: 'Complete your details to improve match and DSS scores.',
        ),
        const SizedBox(height: 16),
        _ProfileCard(user: user, onEdit: openProfileEditor),
      ];
    }

    return [
      _PhotoBanner(
        imageUrl: '${ApiClient.assetBaseUrl}/images/student-dashboard.jpg',
        title: 'Student Dashboard',
        subtitle: 'Your scholarship activity in one mobile workspace.',
      ),
      const SizedBox(height: 16),
      _StatsGrid(stats: stats),
      const SizedBox(height: 16),
      _ProfileSummary(user: user),
      const SizedBox(height: 16),
      _NextStepsCard(steps: nextSteps),
      const SizedBox(height: 16),
      _TopMatchesCard(
        scholarships: scholarships,
        onOpenPrograms: () => setState(() => selectedTab = 1),
      ),
    ];
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
                'APPLICANT MOBILE PORTAL',
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
                'Find scholarships, save programs, and track applications.',
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

class _PhotoBanner extends StatelessWidget {
  const _PhotoBanner({
    required this.imageUrl,
    required this.title,
    required this.subtitle,
  });

  final String imageUrl;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(20),
      child: Stack(
        children: [
          Image.network(
            imageUrl,
            height: 190,
            width: double.infinity,
            fit: BoxFit.cover,
            errorBuilder: (context, error, stackTrace) => Container(
              height: 190,
              color: const Color(0xFF0F172A),
            ),
          ),
          Container(
            height: 190,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  const Color(0xFF081426).withAlpha(225),
                  const Color(0xFF081426).withAlpha(70),
                ],
              ),
            ),
          ),
          Positioned(
            left: 18,
            right: 18,
            bottom: 18,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  subtitle,
                  style: const TextStyle(color: Color(0xFFE2E8F0), height: 1.35),
                ),
              ],
            ),
          ),
        ],
      ),
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

class _EmptyCard extends StatelessWidget {
  const _EmptyCard({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Text(message, style: const TextStyle(color: Color(0xFF64748B))),
      ),
    );
  }
}

class _FinderFiltersCard extends StatelessWidget {
  const _FinderFiltersCard({
    required this.total,
    required this.visible,
    required this.search,
    required this.selectedCategory,
    required this.selectedProviderType,
    required this.selectedIncomeRule,
    required this.selectedDeadline,
    required this.minimumMatch,
    required this.savedOnly,
    required this.categories,
    required this.providerTypes,
    required this.incomeRules,
    required this.onSearchChanged,
    required this.onCategoryChanged,
    required this.onProviderChanged,
    required this.onIncomeChanged,
    required this.onDeadlineChanged,
    required this.onMinimumMatchChanged,
    required this.onSavedOnlyChanged,
    required this.onReset,
  });

  final int total;
  final int visible;
  final String search;
  final String selectedCategory;
  final String selectedProviderType;
  final String selectedIncomeRule;
  final String selectedDeadline;
  final String minimumMatch;
  final bool savedOnly;
  final List<String> categories;
  final List<String> providerTypes;
  final List<String> incomeRules;
  final ValueChanged<String> onSearchChanged;
  final ValueChanged<String?> onCategoryChanged;
  final ValueChanged<String?> onProviderChanged;
  final ValueChanged<String?> onIncomeChanged;
  final ValueChanged<String?> onDeadlineChanged;
  final ValueChanged<String> onMinimumMatchChanged;
  final ValueChanged<bool?> onSavedOnlyChanged;
  final VoidCallback onReset;

  static const deadlineOptions = ['all', 'next_7_days', 'next_30_days', 'no_deadline'];

  @override
  Widget build(BuildContext context) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Expanded(
                  child: Text(
                    'Scholarship Finder',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                  ),
                ),
                TextButton(onPressed: onReset, child: const Text('Reset')),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              '$visible of $total programs shown. Best eligibility matches appear first.',
              style: const TextStyle(color: Color(0xFF64748B)),
            ),
            const SizedBox(height: 14),
            TextField(
              onChanged: onSearchChanged,
              controller: TextEditingController(text: search)..selection = TextSelection.collapsed(offset: search.length),
              decoration: const InputDecoration(
                labelText: 'Search title, provider, course, or location',
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            _FilterDropdown(
              label: 'Category',
              value: dropdownValue(categories, selectedCategory),
              options: categories,
              onChanged: onCategoryChanged,
            ),
            const SizedBox(height: 12),
            _FilterDropdown(
              label: 'Provider type',
              value: dropdownValue(providerTypes, selectedProviderType),
              options: providerTypes,
              onChanged: onProviderChanged,
            ),
            const SizedBox(height: 12),
            _FilterDropdown(
              label: 'Income rule',
              value: dropdownValue(incomeRules, selectedIncomeRule),
              options: incomeRules,
              onChanged: onIncomeChanged,
            ),
            const SizedBox(height: 12),
            _FilterDropdown(
              label: 'Deadline',
              value: dropdownValue(deadlineOptions, selectedDeadline),
              options: deadlineOptions,
              onChanged: onDeadlineChanged,
            ),
            const SizedBox(height: 12),
            TextField(
              keyboardType: TextInputType.number,
              onChanged: onMinimumMatchChanged,
              controller: TextEditingController(text: minimumMatch)..selection = TextSelection.collapsed(offset: minimumMatch.length),
              decoration: const InputDecoration(
                labelText: 'Minimum match percentage',
                hintText: 'Example: 80',
                border: OutlineInputBorder(),
              ),
            ),
            CheckboxListTile(
              value: savedOnly,
              onChanged: onSavedOnlyChanged,
              title: const Text('Saved programs only'),
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
            ),
          ],
        ),
      ),
    );
  }
}

class _FilterDropdown extends StatelessWidget {
  const _FilterDropdown({
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final String label;
  final String value;
  final List<String> options;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<String>(
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
      items: options
          .map(
            (option) => DropdownMenuItem(
              value: option,
              child: Text(friendlyOption(option), overflow: TextOverflow.ellipsis),
            ),
          )
          .toList(),
      onChanged: onChanged,
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

class _ProfileSummary extends StatelessWidget {
  const _ProfileSummary({required this.user});

  final Map<String, dynamic> user;

  @override
  Widget build(BuildContext context) {
    final readinessFields = [
      'first_name',
      'last_name',
      'contact_number',
      'school',
      'course_or_strand',
      'year_level',
      'gwa',
      'grading_scale',
      'income_bracket',
      'city',
      'province',
      'region',
    ];
    final complete = readinessFields.where((key) => stringValue(user[key]).isNotEmpty).length;
    final percent = (complete / readinessFields.length * 100).round();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Profile readiness',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(99),
              child: LinearProgressIndicator(
                value: percent / 100,
                minHeight: 10,
                backgroundColor: const Color(0xFFE2E8F0),
                color: const Color(0xFFF59E0B),
              ),
            ),
            const SizedBox(height: 10),
            Text('$percent% complete - better profile data improves DSS scoring.'),
          ],
        ),
      ),
    );
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.user, required this.onEdit});

  final Map<String, dynamic> user;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final details = [
      _Detail('Name', stringValue(user['name'])),
      _Detail('Email', stringValue(user['email'])),
      _Detail('Contact', stringValue(user['contact_number'], fallback: 'Not provided')),
      _Detail('School', stringValue(user['school'], fallback: 'Not provided')),
      _Detail('Course / strand', stringValue(user['course_or_strand'], fallback: 'Not provided')),
      _Detail('Year level', stringValue(user['year_level'], fallback: 'Not provided')),
      _Detail('GWA / average', stringValue(user['gwa'], fallback: 'Not provided')),
      _Detail('Grading scale', gradingScaleLabel(user['grading_scale'])),
      _Detail('Income bracket', stringValue(user['income_bracket'], fallback: 'Not provided')),
      _Detail('Location', [user['barangay'], user['city'], user['province'], user['region']].map(stringValue).where((value) => value.isNotEmpty).join(', ')),
      _Detail('Guardian', stringValue(user['guardian_name'], fallback: 'Not provided')),
    ];

    return Card(
      elevation: 8,
      shadowColor: Colors.black12,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Expanded(
                  child: Text(
                    'Applicant Profile',
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
                  ),
                ),
                FilledButton.tonal(onPressed: onEdit, child: const Text('Edit')),
              ],
            ),
            const SizedBox(height: 12),
            for (final detail in details)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _DetailTile(detail: detail),
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

class _DssFormulaCard extends StatelessWidget {
  const _DssFormulaCard();

  static const items = [
    _DssFormulaItem('35%', 'Eligibility', 'GWA, course, year, location, and income fit.'),
    _DssFormulaItem('25%', 'Documents', 'Checklist, uploads, and accepted files.'),
    _DssFormulaItem('20%', 'Academic', 'Average or GWA compared with the minimum.'),
    _DssFormulaItem('15%', 'Need', 'Income bracket priority for aid-focused programs.'),
    _DssFormulaItem('5%', 'Review', 'Provider status and decision signal.'),
  ];

  @override
  Widget build(BuildContext context) {
    return Card(
      color: const Color(0xFFEEF2FF),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Decision Support System',
              style: TextStyle(color: Color(0xFF312E81), fontSize: 18, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            const Text(
              'DSS ranks applications for guidance only. Final scholarship decisions stay with the provider.',
              style: TextStyle(color: Color(0xFF4338CA), height: 1.35),
            ),
            const SizedBox(height: 14),
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 48,
                      padding: const EdgeInsets.symmetric(vertical: 7),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        item.weight,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Color(0xFF312E81), fontWeight: FontWeight.w900),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(item.label, style: const TextStyle(fontWeight: FontWeight.w900)),
                          const SizedBox(height: 2),
                          Text(item.detail, style: const TextStyle(color: Color(0xFF475569))),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _DssFormulaItem {
  const _DssFormulaItem(this.weight, this.label, this.detail);

  final String weight;
  final String label;
  final String detail;
}

class _CriterionColors {
  const _CriterionColors({
    required this.background,
    required this.border,
    required this.text,
  });

  final Color background;
  final Color border;
  final Color text;
}

class _TopMatchesCard extends StatelessWidget {
  const _TopMatchesCard({
    required this.scholarships,
    required this.onOpenPrograms,
  });

  final List<Map<String, dynamic>> scholarships;
  final VoidCallback onOpenPrograms;

  @override
  Widget build(BuildContext context) {
    final top = [...scholarships]
      ..sort((first, second) => intValue(asMap(second['eligibility_match'])['score']).compareTo(intValue(asMap(first['eligibility_match'])['score'])));

    return Card(
      color: const Color(0xFF081426),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Top Scholarship Matches',
              style: TextStyle(color: Color(0xFFFDE68A), fontSize: 16, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            if (top.isEmpty)
              const Text('Published programs will appear here.', style: TextStyle(color: Colors.white70))
            else
              for (final scholarship in top.take(3))
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Text(
                    '${stringValue(scholarship['title'])} - ${intValue(asMap(scholarship['eligibility_match'])['score'])}% match',
                    style: const TextStyle(color: Colors.white),
                  ),
                ),
            const SizedBox(height: 8),
            FilledButton.tonal(onPressed: onOpenPrograms, child: const Text('Browse programs')),
          ],
        ),
      ),
    );
  }
}

class _ScholarshipCard extends StatelessWidget {
  const _ScholarshipCard({
    required this.scholarship,
    required this.isSaving,
    required this.alreadyApplied,
    required this.onSave,
    required this.onApply,
  });

  final Map<String, dynamic> scholarship;
  final bool isSaving;
  final bool alreadyApplied;
  final VoidCallback onSave;
  final VoidCallback onApply;

  @override
  Widget build(BuildContext context) {
    final match = asMap(scholarship['eligibility_match']);
    final requirements = documentRequirements(scholarship['requirements']);
    final criteria = asMapList(match['criteria']);
    final score = intValue(match['score']);

    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        stringValue(scholarship['provider'] is Map ? scholarship['provider']['name'] : null, fallback: 'Scholarship Provider'),
                        style: const TextStyle(color: Color(0xFF047857), fontSize: 12, fontWeight: FontWeight.w900, letterSpacing: 1),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        stringValue(scholarship['title']),
                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
                      ),
                    ],
                  ),
                ),
                _ScorePill(score: score, label: '$score%'),
              ],
            ),
            const SizedBox(height: 10),
            Text(stringValue(scholarship['description']), maxLines: 3, overflow: TextOverflow.ellipsis),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _InfoChip(icon: Icons.payments, label: moneyValue(scholarship['award_amount'])),
                _InfoChip(icon: Icons.grade, label: 'GWA ${stringValue(scholarship['minimum_gwa'], fallback: 'Any')}'),
                _InfoChip(icon: Icons.calendar_month, label: stringValue(scholarship['deadline'], fallback: 'No deadline')),
                _InfoChip(icon: Icons.description, label: '${requirements.length} docs'),
              ],
            ),
            const SizedBox(height: 12),
            Text(stringValue(match['summary'], fallback: 'Review eligibility before applying.')),
            if (criteria.isNotEmpty) ...[
              const SizedBox(height: 14),
              const Text('Eligibility pre-check', style: TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              for (final criterion in criteria.take(5))
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: _CriterionTile(criterion: criterion),
                ),
            ],
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: isSaving ? null : onSave,
                    icon: Icon(scholarship['is_saved'] == true ? Icons.bookmark : Icons.bookmark_border),
                    label: Text(scholarship['is_saved'] == true ? 'Saved' : 'Save'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    onPressed: alreadyApplied ? null : onApply,
                    child: Text(alreadyApplied ? 'Applied' : 'Apply'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _ApplicationCard extends StatelessWidget {
  const _ApplicationCard({required this.application});

  final Map<String, dynamic> application;

  @override
  Widget build(BuildContext context) {
    final scholarship = asMap(application['scholarship']);
    final dss = asMap(application['dss_breakdown']);
    final readiness = asMap(application['document_readiness']);
    final documents = asMapList(application['documents']);
    final timeline = asMapList(application['timeline']);
    final dssScore = intValue(application['dss_score']);

    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        stringValue(scholarship['title'], fallback: 'Scholarship'),
                        style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 4),
                      Text('Submitted ${stringValue(application['submitted_at'], fallback: 'recently')}'),
                    ],
                  ),
                ),
                _StatusPill(label: labelFromKey(application['status'])),
              ],
            ),
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFEEF2FF),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          stringValue(dss['label'], fallback: labelFromKey(application['dss_recommendation'])),
                          style: const TextStyle(color: Color(0xFF312E81), fontWeight: FontWeight.w900),
                        ),
                      ),
                      _ScorePill(score: dssScore, label: '$dssScore% DSS'),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(stringValue(dss['summary'], fallback: 'DSS helps providers prioritize applications.')),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _InfoChip(icon: Icons.checklist, label: '${intValue(readiness['confirmed'])}/${intValue(readiness['required'])} confirmed'),
                _InfoChip(icon: Icons.upload_file, label: '${intValue(readiness['uploaded'])} uploaded'),
                _InfoChip(icon: Icons.verified, label: '${intValue(readiness['accepted'])} accepted'),
              ],
            ),
            if (documents.isNotEmpty) ...[
              const SizedBox(height: 12),
              const Text('Documents', style: TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              for (final document in documents)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: _DocumentRow(document: document),
                ),
            ],
            if (timeline.isNotEmpty) ...[
              const SizedBox(height: 12),
              const Text('Timeline', style: TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              for (final event in timeline.take(3))
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text('${labelFromKey(event['to_status'])} - ${stringValue(event['changed_at'], fallback: 'recently')}'),
                ),
            ],
            const SizedBox(height: 8),
            const Text(
              'DSS is guidance only. Final decisions are made by scholarship providers.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

class _CriterionTile extends StatelessWidget {
  const _CriterionTile({required this.criterion});

  final Map<String, dynamic> criterion;

  @override
  Widget build(BuildContext context) {
    final status = stringValue(criterion['status'], fallback: 'info');
    final colors = _criterionColors(status);
    final studentValue = stringValue(
      criterion['student_value'] ?? criterion['studentValue'],
      fallback: 'Not set',
    );

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: colors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  stringValue(criterion['label'], fallback: 'Requirement'),
                  style: TextStyle(color: colors.text, fontWeight: FontWeight.w900),
                ),
              ),
              Text(
                labelFromKey(status),
                style: TextStyle(color: colors.text, fontSize: 12, fontWeight: FontWeight.w900),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text('Profile: $studentValue', style: TextStyle(color: colors.text)),
          if (stringValue(criterion['requirement']).isNotEmpty)
            Text('Required: ${stringValue(criterion['requirement'])}', style: TextStyle(color: colors.text)),
          if (stringValue(criterion['note']).isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(stringValue(criterion['note']), style: TextStyle(color: colors.text.withAlpha(210), fontSize: 12)),
          ],
        ],
      ),
    );
  }
}

class _ApplicationSheet extends StatefulWidget {
  const _ApplicationSheet({
    required this.scholarship,
    required this.apiClient,
  });

  final Map<String, dynamic> scholarship;
  final ApiClient apiClient;

  @override
  State<_ApplicationSheet> createState() => _ApplicationSheetState();
}

class _ApplicationSheetState extends State<_ApplicationSheet> {
  final notesController = TextEditingController();
  late final List<String> requirements;
  final Set<String> selected = {};
  bool isSubmitting = false;

  @override
  void initState() {
    super.initState();
    requirements = documentRequirements(widget.scholarship['requirements']);
  }

  @override
  void dispose() {
    notesController.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (requirements.isNotEmpty && selected.length != requirements.length) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Confirm all listed documents before submitting.')),
      );
      return;
    }

    setState(() => isSubmitting = true);

    try {
      await widget.apiClient.submitApplication(
        scholarshipId: intValue(widget.scholarship['id']),
        documentChecklist: selected.toList(),
        notes: notesController.text.trim(),
      );

      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => isSubmitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              stringValue(widget.scholarship['title']),
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            const Text('Confirm prepared documents. Uploading files is still handled on the web portal.'),
            const SizedBox(height: 16),
            if (requirements.isEmpty)
              const Text('No document requirements are listed.')
            else
              for (final requirement in requirements)
                CheckboxListTile(
                  value: selected.contains(requirement),
                  onChanged: (value) {
                    setState(() {
                      if (value == true) {
                        selected.add(requirement);
                      } else {
                        selected.remove(requirement);
                      }
                    });
                  },
                  title: Text(requirement),
                  contentPadding: EdgeInsets.zero,
                ),
            const SizedBox(height: 12),
            TextField(
              controller: notesController,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Optional note',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: isSubmitting ? null : submit,
              child: Text(isSubmitting ? 'Submitting...' : 'Submit application'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ProfileEditor extends StatefulWidget {
  const _ProfileEditor({required this.user, required this.apiClient});

  final Map<String, dynamic> user;
  final ApiClient apiClient;

  @override
  State<_ProfileEditor> createState() => _ProfileEditorState();
}

class _ProfileEditorState extends State<_ProfileEditor> {
  final formKey = GlobalKey<FormState>();
  final controllers = <String, TextEditingController>{};
  bool isSaving = false;

  static const fields = [
    _ProfileField('first_name', 'First name', required: true),
    _ProfileField('middle_initial', 'M.I.', required: true, maxLength: 1),
    _ProfileField('last_name', 'Last name', required: true),
    _ProfileField('contact_number', 'Contact number', required: true, keyboardType: TextInputType.phone),
    _ProfileField('school', 'School'),
    _ProfileField('course_or_strand', 'Course / strand'),
    _ProfileField('year_level', 'Year level'),
    _ProfileField('enrollment_status', 'Enrollment status'),
    _ProfileField('gwa', 'GWA / average', keyboardType: TextInputType.number),
    _ProfileField('grading_scale', 'Grading scale (percentage / grade_point)'),
    _ProfileField('income_bracket', 'Income bracket'),
    _ProfileField('barangay', 'Barangay'),
    _ProfileField('city', 'City / municipality'),
    _ProfileField('province', 'Province'),
    _ProfileField('region', 'Region'),
    _ProfileField('address', 'Address'),
    _ProfileField('birthdate', 'Birthdate YYYY-MM-DD'),
    _ProfileField('guardian_name', 'Guardian name'),
    _ProfileField('guardian_contact', 'Guardian contact', keyboardType: TextInputType.phone),
  ];

  @override
  void initState() {
    super.initState();
    for (final field in fields) {
      controllers[field.key] = TextEditingController(text: stringValue(widget.user[field.key]));
    }
  }

  @override
  void dispose() {
    for (final controller in controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    if (!formKey.currentState!.validate()) {
      return;
    }

    setState(() => isSaving = true);

    try {
      final payload = <String, dynamic>{};
      for (final field in fields) {
        payload[field.key] = controllers[field.key]!.text.trim();
      }
      payload['grading_scale'] = normalizeGradingScale(payload['grading_scale']);

      await widget.apiClient.updateProfile(payload);

      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => isSaving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Form(
          key: formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Edit applicant profile',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 16),
              for (final field in fields)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: TextFormField(
                    controller: controllers[field.key],
                    maxLength: field.maxLength,
                    keyboardType: field.keyboardType,
                    decoration: InputDecoration(
                      labelText: field.label,
                      counterText: '',
                      border: const OutlineInputBorder(),
                    ),
                    validator: (value) {
                      if (field.required && stringValue(value).isEmpty) {
                        return 'Required';
                      }

                      if (field.key == 'middle_initial' && !RegExp(r'^[A-Za-z]$').hasMatch(stringValue(value))) {
                        return 'Use 1 letter';
                      }

                      return null;
                    },
                  ),
                ),
              FilledButton(
                onPressed: isSaving ? null : save,
                child: Text(isSaving ? 'Saving...' : 'Save profile'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ProfileField {
  const _ProfileField(
    this.key,
    this.label, {
    this.required = false,
    this.maxLength,
    this.keyboardType,
  });

  final String key;
  final String label;
  final bool required;
  final int? maxLength;
  final TextInputType? keyboardType;
}

class _Detail {
  const _Detail(this.label, this.value);

  final String label;
  final String value;
}

class _DetailTile extends StatelessWidget {
  const _DetailTile({required this.detail});

  final _Detail detail;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            detail.label,
            style: const TextStyle(color: Color(0xFF64748B), fontSize: 12, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 4),
          Text(
            detail.value.isEmpty ? 'Not provided' : detail.value,
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
        ],
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  const _InfoChip({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Chip(
      avatar: Icon(icon, size: 16),
      label: Text(label),
      backgroundColor: const Color(0xFFF1F5F9),
      side: BorderSide.none,
    );
  }
}

class _ScorePill extends StatelessWidget {
  const _ScorePill({required this.score, required this.label});

  final int score;
  final String label;

  @override
  Widget build(BuildContext context) {
    final color = score >= 80
        ? Colors.green
        : score >= 55
            ? Colors.amber
            : Colors.red;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.shade100,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        label,
        style: TextStyle(color: color.shade800, fontWeight: FontWeight.w900),
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: const Color(0xFFE0F2FE),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        label,
        style: const TextStyle(color: Color(0xFF075985), fontWeight: FontWeight.w900),
      ),
    );
  }
}

class _DocumentRow extends StatelessWidget {
  const _DocumentRow({required this.document});

  final Map<String, dynamic> document;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          const Icon(Icons.description_outlined),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringValue(document['document_name']), style: const TextStyle(fontWeight: FontWeight.w900)),
                Text(labelFromKey(document['status']), style: const TextStyle(color: Color(0xFF64748B))),
                if (stringValue(document['review_notes']).isNotEmpty)
                  Text(stringValue(document['review_notes']), style: const TextStyle(color: Color(0xFF92400E))),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

Map<String, dynamic> asMap(Object? value) {
  if (value is Map<String, dynamic>) {
    return value;
  }

  if (value is Map) {
    return Map<String, dynamic>.from(value);
  }

  return {};
}

List<Map<String, dynamic>> asMapList(Object? value) {
  if (value is List) {
    return value.map(asMap).toList();
  }

  return [];
}

List<Map<String, dynamic>> rankedScholarships(List<Map<String, dynamic>> scholarships) {
  return [...scholarships]
    ..sort(
      (first, second) => intValue(asMap(second['eligibility_match'])['score'])
          .compareTo(intValue(asMap(first['eligibility_match'])['score'])),
    );
}

List<Map<String, dynamic>> filterScholarships({
  required List<Map<String, dynamic>> scholarships,
  required String search,
  required String category,
  required String providerType,
  required String incomeRule,
  required String deadline,
  required String minimumMatch,
  required bool savedOnly,
}) {
  final keyword = search.trim().toLowerCase();
  final minimum = int.tryParse(minimumMatch.trim());

  final filtered = scholarships.where((scholarship) {
    final provider = asMap(scholarship['provider']);
    final match = asMap(scholarship['eligibility_match']);
    final score = intValue(match['score']);
    final matchesSearch = keyword.isEmpty ||
        [
          scholarship['title'],
          scholarship['description'],
          scholarship['category'],
          scholarship['eligibility'],
          scholarship['eligible_courses'],
          scholarship['eligible_year_levels'],
          scholarship['eligible_locations'],
          scholarship['income_requirement'],
          scholarship['requirements'],
          provider['name'],
        ].any((value) => stringValue(value).toLowerCase().contains(keyword));

    return matchesSearch &&
        optionMatches(scholarship['category'], category) &&
        optionMatches(provider['type'], providerType) &&
        textMatches(scholarship['income_requirement'], incomeRule) &&
        deadlineMatches(scholarship, deadline) &&
        (minimum == null || score >= minimum) &&
        (!savedOnly || scholarship['is_saved'] == true);
  }).toList();

  return rankedScholarships(filtered)
    ..sort((first, second) {
      final scoreDifference = intValue(asMap(second['eligibility_match'])['score'])
          .compareTo(intValue(asMap(first['eligibility_match'])['score']));

      if (scoreDifference != 0) {
        return scoreDifference;
      }

      return deadlineSortValue(first).compareTo(deadlineSortValue(second));
    });
}

List<String> filterOptions(List<Map<String, dynamic>> scholarships, String Function(Map<String, dynamic>) selector) {
  final options = scholarships
      .map(selector)
      .where((value) => value.trim().isNotEmpty)
      .toSet()
      .toList()
    ..sort();

  return ['all', ...options];
}

String dropdownValue(List<String> options, String selected) {
  return options.contains(selected) ? selected : 'all';
}

String friendlyOption(String value) {
  if (value == 'all') {
    return 'All';
  }

  if (value == 'next_7_days') {
    return 'Due in 7 days';
  }

  if (value == 'next_30_days') {
    return 'Due in 30 days';
  }

  if (value == 'no_deadline') {
    return 'No deadline';
  }

  return labelFromKey(value);
}

bool optionMatches(Object? value, String selected) {
  return selected == 'all' || stringValue(value) == selected;
}

bool textMatches(Object? value, String filter) {
  if (filter == 'all' || filter.trim().isEmpty) {
    return true;
  }

  final haystack = stringValue(value).toLowerCase();
  final needle = filter.toLowerCase();

  return haystack.isEmpty || haystack.contains(needle) || needle.contains(haystack);
}

bool deadlineMatches(Map<String, dynamic> scholarship, String filter) {
  final days = daysUntilDeadline(scholarship['deadline']);

  if (filter == 'no_deadline') {
    return days == null;
  }

  if (filter == 'next_7_days') {
    return days != null && days >= 0 && days <= 7;
  }

  if (filter == 'next_30_days') {
    return days != null && days >= 0 && days <= 30;
  }

  return true;
}

int deadlineSortValue(Map<String, dynamic> scholarship) {
  final days = daysUntilDeadline(scholarship['deadline']);

  return days ?? 99999;
}

int? daysUntilDeadline(Object? value) {
  final text = stringValue(value);

  if (text.isEmpty) {
    return null;
  }

  final parsed = parsePortalDate(text);

  if (parsed == null) {
    return null;
  }

  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final deadline = DateTime(parsed.year, parsed.month, parsed.day);

  return deadline.difference(today).inDays;
}

DateTime? parsePortalDate(String value) {
  final direct = DateTime.tryParse(value);

  if (direct != null) {
    return direct;
  }

  final match = RegExp(r'^([A-Za-z]{3}) (\d{1,2}), (\d{4})$').firstMatch(value.trim());

  if (match == null) {
    return null;
  }

  const months = {
    'jan': 1,
    'feb': 2,
    'mar': 3,
    'apr': 4,
    'may': 5,
    'jun': 6,
    'jul': 7,
    'aug': 8,
    'sep': 9,
    'oct': 10,
    'nov': 11,
    'dec': 12,
  };
  final month = months[match.group(1)!.toLowerCase()];
  final day = int.tryParse(match.group(2)!);
  final year = int.tryParse(match.group(3)!);

  if (month == null || day == null || year == null) {
    return null;
  }

  return DateTime(year, month, day);
}

_CriterionColors _criterionColors(String status) {
  if (status == 'pass') {
    return const _CriterionColors(
      background: Color(0xFFECFDF5),
      border: Color(0xFFA7F3D0),
      text: Color(0xFF065F46),
    );
  }

  if (status == 'fail') {
    return const _CriterionColors(
      background: Color(0xFFFFF1F2),
      border: Color(0xFFFECDD3),
      text: Color(0xFF9F1239),
    );
  }

  if (status == 'missing') {
    return const _CriterionColors(
      background: Color(0xFFFFFBEB),
      border: Color(0xFFFDE68A),
      text: Color(0xFF92400E),
    );
  }

  return const _CriterionColors(
    background: Color(0xFFF8FAFC),
    border: Color(0xFFE2E8F0),
    text: Color(0xFF475569),
  );
}

String stringValue(Object? value, {String fallback = ''}) {
  if (value == null) {
    return fallback;
  }

  final text = value.toString();
  return text.isEmpty ? fallback : text;
}

int intValue(Object? value) {
  if (value is int) {
    return value;
  }

  if (value is num) {
    return value.round();
  }

  return int.tryParse(stringValue(value)) ?? 0;
}

String labelFromKey(Object? value) {
  final text = stringValue(value, fallback: 'pending').replaceAll('_', ' ');
  return text
      .split(' ')
      .where((part) => part.isNotEmpty)
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .join(' ');
}

String gradingScaleLabel(Object? value) {
  final scale = stringValue(value);

  if (scale == 'percentage') {
    return 'Percentage average';
  }

  if (scale == 'grade_point') {
    return 'GWA grade point';
  }

  return 'Not provided';
}

String normalizeGradingScale(Object? value) {
  final scale = stringValue(value).toLowerCase().trim();

  if (scale.isEmpty) {
    return '';
  }

  if (scale.contains('point') || scale.contains('gwa')) {
    return 'grade_point';
  }

  if (scale.contains('percent') || scale.contains('average')) {
    return 'percentage';
  }

  return '';
}

String moneyValue(Object? value) {
  final amount = double.tryParse(stringValue(value));
  if (amount == null) {
    return 'Amount not set';
  }

  return 'PHP ${amount.toStringAsFixed(2)}';
}

List<String> documentRequirements(Object? value) {
  return stringValue(value)
      .split(RegExp(r'\r?\n|,'))
      .map((requirement) => requirement.trim())
      .where((requirement) => requirement.isNotEmpty)
      .toList();
}
