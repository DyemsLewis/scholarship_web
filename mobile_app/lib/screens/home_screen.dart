import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:url_launcher/url_launcher.dart';

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
  bool isDocumentsLoading = false;
  bool isDocumentSaving = false;
  bool documentsLoaded = false;
  String? errorMessage;
  String? documentsErrorMessage;
  int selectedTab = 0;
  Map<String, dynamic> user = {};
  Map<String, dynamic> stats = {};
  Map<String, dynamic> documentStats = {};
  List<Map<String, dynamic>> scholarships = [];
  List<Map<String, dynamic>> applications = [];
  List<Map<String, dynamic>> notifications = [];
  List<Map<String, dynamic>> preparedDocuments = [];
  List<Map<String, dynamic>> documentApplications = [];
  List<String> documentOptions = [];
  List<dynamic> nextSteps = [];
  String programSearch = '';
  String selectedCategory = 'all';
  String selectedProviderType = 'all';
  String selectedEducationLevel = 'all';
  String selectedSchoolType = 'all';
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
        notifications = asMapList(data['notifications']);
        nextSteps = data['next_steps'] is List
            ? data['next_steps'] as List
            : [];
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

  Future<void> loadDocuments({bool showLoading = true}) async {
    if (showLoading) {
      setState(() {
        isDocumentsLoading = true;
        documentsErrorMessage = null;
      });
    }

    try {
      final data = await widget.apiClient.documents();

      setState(() {
        documentStats = asMap(data['stats']);
        preparedDocuments = asMapList(data['prepared_documents']);
        documentApplications = asMapList(data['applications']);
        documentOptions = stringList(data['document_options']);
        documentsLoaded = true;
        documentsErrorMessage = null;
      });
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          documentsErrorMessage = error.message;
        });
      }
    } finally {
      if (mounted && showLoading) {
        setState(() {
          isDocumentsLoading = false;
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

  Future<void> openProfileEditor() async {
    final updated = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) =>
          _ProfileEditor(user: user, apiClient: widget.apiClient),
    );

    if (updated == true) {
      showMessage('Profile updated.');
      await loadPortal();
    }
  }

  void showMessage(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> markNotificationRead(Map<String, dynamic> notification) async {
    final notificationId = intValue(notification['id']);

    if (notificationId == 0) {
      return;
    }

    try {
      final response = await widget.apiClient.markNotificationRead(
        notificationId,
      );

      setState(() {
        notifications = asMapList(response['notifications']);
      });
    } on ApiException catch (error) {
      showMessage(error.message);
    }
  }

  Future<void> openPreparedDocumentUploader() async {
    if (documentOptions.isEmpty) {
      await loadDocuments();
    }

    if (!mounted) {
      return;
    }

    final uploaded = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _PreparedDocumentSheet(
        documentOptions: documentOptions,
        apiClient: widget.apiClient,
      ),
    );

    if (uploaded == true) {
      showMessage('Prepared document uploaded.');
      await loadDocuments(showLoading: false);
      await loadPortal();
    }
  }

  Future<void> deletePreparedDocument(Map<String, dynamic> document) async {
    final documentId = intValue(document['id']);

    if (documentId == 0) {
      return;
    }

    setState(() => isDocumentSaving = true);

    try {
      final response = await widget.apiClient.deletePreparedDocument(
        documentId,
      );
      showMessage(
        stringValue(
          response['message'],
          fallback: 'Prepared document removed.',
        ),
      );
      await loadDocuments(showLoading: false);
      await loadPortal();
    } on ApiException catch (error) {
      showMessage(error.message);
    } finally {
      if (mounted) {
        setState(() => isDocumentSaving = false);
      }
    }
  }

  Future<void> openScholarshipDetail(Map<String, dynamic> scholarship) async {
    final scholarshipId = intValue(scholarship['id']);
    final alreadyApplied =
        scholarship['has_applied'] == true ||
        applications.any(
          (application) =>
              intValue(asMap(application['scholarship'])['id']) ==
              scholarshipId,
        );

    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (context) => _ScholarshipDetailScreen(
          scholarship: scholarship,
          apiClient: widget.apiClient,
          alreadyApplied: alreadyApplied,
        ),
      ),
    );

    if (mounted) {
      await loadPortal();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      bottomNavigationBar: NavigationBar(
        selectedIndex: selectedTab,
        onDestinationSelected: (index) {
          setState(() => selectedTab = index);

          if (index == 2 && !documentsLoaded) {
            loadDocuments();
          }
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard),
            label: 'Home',
          ),
          NavigationDestination(
            icon: Icon(Icons.school_outlined),
            selectedIcon: Icon(Icons.school),
            label: 'Programs',
          ),
          NavigationDestination(
            icon: Icon(Icons.folder_outlined),
            selectedIcon: Icon(Icons.folder),
            label: 'Docs',
          ),
          NavigationDestination(
            icon: Icon(Icons.assignment_outlined),
            selectedIcon: Icon(Icons.assignment),
            label: 'Applications',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Profile',
          ),
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
            onRefresh: selectedTab == 2 ? loadDocuments : loadPortal,
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
        educationLevel: selectedEducationLevel,
        schoolType: selectedSchoolType,
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
          selectedEducationLevel: selectedEducationLevel,
          selectedSchoolType: selectedSchoolType,
          selectedIncomeRule: selectedIncomeRule,
          selectedDeadline: selectedDeadline,
          minimumMatch: minimumMatch,
          savedOnly: savedOnly,
          categories: filterOptions(
            scholarships,
            (scholarship) => stringValue(scholarship['category']),
          ),
          providerTypes: filterOptions(
            scholarships,
            (scholarship) =>
                stringValue(asMap(scholarship['provider'])['type']),
          ),
          educationLevels: criteriaFilterOptions(
            scholarships,
            'eligible_education_levels',
          ),
          schoolTypes: criteriaFilterOptions(
            scholarships,
            'eligible_school_types',
          ),
          incomeRules: filterOptions(
            scholarships,
            (scholarship) => stringValue(scholarship['income_requirement']),
          ),
          onSearchChanged: (value) => setState(() => programSearch = value),
          onCategoryChanged: (value) =>
              setState(() => selectedCategory = value ?? 'all'),
          onProviderChanged: (value) =>
              setState(() => selectedProviderType = value ?? 'all'),
          onEducationLevelChanged: (value) =>
              setState(() => selectedEducationLevel = value ?? 'all'),
          onSchoolTypeChanged: (value) =>
              setState(() => selectedSchoolType = value ?? 'all'),
          onIncomeChanged: (value) =>
              setState(() => selectedIncomeRule = value ?? 'all'),
          onDeadlineChanged: (value) =>
              setState(() => selectedDeadline = value ?? 'all'),
          onMinimumMatchChanged: (value) =>
              setState(() => minimumMatch = value),
          onSavedOnlyChanged: (value) =>
              setState(() => savedOnly = value ?? false),
          onReset: () => setState(() {
            programSearch = '';
            selectedCategory = 'all';
            selectedProviderType = 'all';
            selectedEducationLevel = 'all';
            selectedSchoolType = 'all';
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
          const _EmptyCard(
            message: 'No scholarships match your current finder filters.',
          )
        else
          for (final scholarship in filteredPrograms)
            _ScholarshipCard(
              scholarship: scholarship,
              isSaving: isSaving,
              alreadyApplied:
                  scholarship['has_applied'] == true ||
                  applications.any(
                    (application) =>
                        intValue(asMap(application['scholarship'])['id']) ==
                        intValue(scholarship['id']),
                  ),
              onSave: () => toggleSave(scholarship),
              onView: () => openScholarshipDetail(scholarship),
            ),
      ];
    }

    if (selectedTab == 2) {
      return [
        _PhotoBanner(
          imageUrl:
              '${ApiClient.assetBaseUrl}/images/application-documents.jpg',
          title: 'Prepared Documents',
          subtitle:
              'Upload documents before applying so matching and submission are easier.',
        ),
        const SizedBox(height: 16),
        if (isDocumentsLoading)
          const _LoadingCard()
        else if (documentsErrorMessage != null)
          _ErrorCard(
            message: documentsErrorMessage!,
            onRetry: () => loadDocuments(),
          )
        else if (!documentsLoaded)
          const _LoadingCard()
        else ...[
          _DocumentHubCard(
            stats: documentStats,
            preparedDocuments: preparedDocuments,
            applications: documentApplications,
            isSaving: isDocumentSaving,
            onUpload: openPreparedDocumentUploader,
            onDelete: deletePreparedDocument,
          ),
        ],
      ];
    }

    if (selectedTab == 3) {
      return [
        _PhotoBanner(
          imageUrl:
              '${ApiClient.assetBaseUrl}/images/application-documents.jpg',
          title: 'Applications',
          subtitle: 'Track DSS guidance, provider review, and document status.',
        ),
        const SizedBox(height: 16),
        const _DssFormulaCard(),
        const SizedBox(height: 16),
        if (applications.isEmpty)
          const _EmptyCard(message: 'No applications submitted yet.')
        else
          for (final application in applications)
            _ApplicationCard(application: application),
      ];
    }

    if (selectedTab == 4) {
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
      _NotificationsCard(
        notifications: notifications,
        onMarkRead: markNotificationRead,
      ),
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
            errorBuilder: (context, error, stackTrace) =>
                Container(height: 190, color: const Color(0xFF0F172A)),
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
                  style: const TextStyle(
                    color: Color(0xFFE2E8F0),
                    height: 1.35,
                  ),
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
    required this.selectedEducationLevel,
    required this.selectedSchoolType,
    required this.selectedIncomeRule,
    required this.selectedDeadline,
    required this.minimumMatch,
    required this.savedOnly,
    required this.categories,
    required this.providerTypes,
    required this.educationLevels,
    required this.schoolTypes,
    required this.incomeRules,
    required this.onSearchChanged,
    required this.onCategoryChanged,
    required this.onProviderChanged,
    required this.onEducationLevelChanged,
    required this.onSchoolTypeChanged,
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
  final String selectedEducationLevel;
  final String selectedSchoolType;
  final String selectedIncomeRule;
  final String selectedDeadline;
  final String minimumMatch;
  final bool savedOnly;
  final List<String> categories;
  final List<String> providerTypes;
  final List<String> educationLevels;
  final List<String> schoolTypes;
  final List<String> incomeRules;
  final ValueChanged<String> onSearchChanged;
  final ValueChanged<String?> onCategoryChanged;
  final ValueChanged<String?> onProviderChanged;
  final ValueChanged<String?> onEducationLevelChanged;
  final ValueChanged<String?> onSchoolTypeChanged;
  final ValueChanged<String?> onIncomeChanged;
  final ValueChanged<String?> onDeadlineChanged;
  final ValueChanged<String> onMinimumMatchChanged;
  final ValueChanged<bool?> onSavedOnlyChanged;
  final VoidCallback onReset;

  static const deadlineOptions = [
    'all',
    'next_7_days',
    'next_30_days',
    'no_deadline',
  ];

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
              controller: TextEditingController(text: search)
                ..selection = TextSelection.collapsed(offset: search.length),
              decoration: const InputDecoration(
                labelText: 'Search title, provider, track, grade, or location',
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
              label: 'Education level',
              value: dropdownValue(educationLevels, selectedEducationLevel),
              options: educationLevels,
              onChanged: onEducationLevelChanged,
            ),
            const SizedBox(height: 12),
            _FilterDropdown(
              label: 'School type',
              value: dropdownValue(schoolTypes, selectedSchoolType),
              options: schoolTypes,
              onChanged: onSchoolTypeChanged,
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
              controller: TextEditingController(text: minimumMatch)
                ..selection = TextSelection.collapsed(
                  offset: minimumMatch.length,
                ),
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
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
      ),
      items: options
          .map(
            (option) => DropdownMenuItem(
              value: option,
              child: Text(
                friendlyOption(option),
                overflow: TextOverflow.ellipsis,
              ),
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
      'birthdate',
      'education_level',
      'school',
      'year_level',
      'gwa',
      'grading_scale',
      'income_bracket',
      'city',
      'province',
      'region',
    ];
    final complete = readinessFields
        .where((key) => stringValue(user[key]).isNotEmpty)
        .length;
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
            Text(
              '$percent% complete - better profile data improves DSS scoring.',
            ),
          ],
        ),
      ),
    );
  }
}

class _NotificationsCard extends StatelessWidget {
  const _NotificationsCard({
    required this.notifications,
    required this.onMarkRead,
  });

  final List<Map<String, dynamic>> notifications;
  final ValueChanged<Map<String, dynamic>> onMarkRead;

  @override
  Widget build(BuildContext context) {
    final unreadCount = notifications
        .where((notification) => stringValue(notification['read_at']).isEmpty)
        .length;

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
                    'Notifications',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                  ),
                ),
                if (unreadCount > 0)
                  _StatusPill(label: '$unreadCount new', status: 'submitted'),
              ],
            ),
            const SizedBox(height: 10),
            if (notifications.isEmpty)
              const Text(
                'No portal reminders yet.',
                style: TextStyle(color: Color(0xFF64748B)),
              )
            else
              for (final notification in notifications.take(5))
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _NotificationTile(
                    notification: notification,
                    onMarkRead: () => onMarkRead(notification),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({
    required this.notification,
    required this.onMarkRead,
  });

  final Map<String, dynamic> notification;
  final VoidCallback onMarkRead;

  @override
  Widget build(BuildContext context) {
    final isRead = stringValue(notification['read_at']).isNotEmpty;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isRead ? const Color(0xFFF8FAFC) : const Color(0xFFFFFBEB),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isRead ? const Color(0xFFE2E8F0) : const Color(0xFFFDE68A),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                isRead
                    ? Icons.notifications_none
                    : Icons.notifications_active_outlined,
                color: isRead
                    ? const Color(0xFF64748B)
                    : const Color(0xFF92400E),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      stringValue(
                        notification['title'],
                        fallback: 'Portal update',
                      ),
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      stringValue(notification['message']),
                      style: const TextStyle(
                        color: Color(0xFF475569),
                        height: 1.35,
                      ),
                    ),
                    if (stringValue(notification['created_at']).isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Text(
                          stringValue(notification['created_at']),
                          style: const TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 12,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
          if (!isRead) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: onMarkRead,
                child: const Text('Mark read'),
              ),
            ),
          ],
        ],
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
      _Detail('Suffix', stringValue(user['suffix'], fallback: 'Not provided')),
      _Detail('Gender', profileLabelFromKey(user['gender'])),
      _Detail(
        'Contact',
        stringValue(user['contact_number'], fallback: 'Not provided'),
      ),
      _Detail('Education level', profileLabelFromKey(user['education_level'])),
      _Detail(
        'School / learning institution',
        stringValue(user['school'], fallback: 'Not provided'),
      ),
      _Detail('School type', profileLabelFromKey(user['school_type'])),
      _Detail(
        'LRN / student number',
        stringValue(user['learner_reference_number'], fallback: 'Not provided'),
      ),
      _Detail(
        'Track / strand / course',
        stringValue(user['course_or_strand'], fallback: 'Not provided'),
      ),
      _Detail(
        'Grade / year level',
        stringValue(user['year_level'], fallback: 'Not provided'),
      ),
      _Detail(
        'GWA / general average',
        stringValue(user['gwa'], fallback: 'Not provided'),
      ),
      _Detail('Grading scale', gradingScaleLabel(user['grading_scale'])),
      _Detail(
        'Income bracket',
        stringValue(user['income_bracket'], fallback: 'Not provided'),
      ),
      _Detail(
        'Household size',
        stringValue(user['household_size'], fallback: 'Not provided'),
      ),
      _Detail(
        'Preferred scholarship categories',
        stringValue(user['preferred_categories'], fallback: 'Not provided'),
      ),
      _Detail(
        'Preferred locations',
        stringValue(user['preferred_locations'], fallback: 'Not provided'),
      ),
      _Detail(
        'Willing to relocate',
        profileLabelFromKey(user['willing_to_relocate']),
      ),
      _Detail(
        'Support needs',
        stringValue(user['support_needs'], fallback: 'Not provided'),
      ),
      _Detail(
        'Scholarship goal',
        stringValue(user['scholarship_goal'], fallback: 'Not provided'),
      ),
      _Detail(
        'Location',
        [
          user['barangay'],
          user['city'],
          user['province'],
          user['region'],
        ].map(stringValue).where((value) => value.isNotEmpty).join(', '),
      ),
      _Detail(
        'Coordinates',
        coordinateLabel(user['latitude'], user['longitude']),
      ),
      _Detail(
        'Guardian',
        stringValue(user['guardian_name'], fallback: 'Not provided'),
      ),
      _Detail(
        'Guardian contact',
        stringValue(user['guardian_contact'], fallback: 'Not provided'),
      ),
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
                FilledButton.tonal(
                  onPressed: onEdit,
                  child: const Text('Edit'),
                ),
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
    _DssFormulaItem(
      '35%',
      'Eligibility',
      'Average, track, grade/year, location, and income fit.',
    ),
    _DssFormulaItem(
      '25%',
      'Documents',
      'Checklist, uploads, and accepted files.',
    ),
    _DssFormulaItem(
      '20%',
      'Academic',
      'Average or GWA compared with the minimum.',
    ),
    _DssFormulaItem(
      '15%',
      'Need',
      'Income bracket priority for aid-focused programs.',
    ),
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
              style: TextStyle(
                color: Color(0xFF312E81),
                fontSize: 18,
                fontWeight: FontWeight.w900,
              ),
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
                        style: const TextStyle(
                          color: Color(0xFF312E81),
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            item.label,
                            style: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            item.detail,
                            style: const TextStyle(color: Color(0xFF475569)),
                          ),
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

class _DocumentHubCard extends StatelessWidget {
  const _DocumentHubCard({
    required this.stats,
    required this.preparedDocuments,
    required this.applications,
    required this.isSaving,
    required this.onUpload,
    required this.onDelete,
  });

  final Map<String, dynamic> stats;
  final List<Map<String, dynamic>> preparedDocuments;
  final List<Map<String, dynamic>> applications;
  final bool isSaving;
  final VoidCallback onUpload;
  final ValueChanged<Map<String, dynamic>> onDelete;

  @override
  Widget build(BuildContext context) {
    final statItems = [
      _StatData('Prepared', stats['prepared'] ?? 0, Colors.blue),
      _StatData('Uploaded', stats['uploaded'] ?? 0, Colors.green),
      _StatData('Review', stats['pending'] ?? 0, Colors.amber),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'Document wallet',
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    FilledButton.icon(
                      onPressed: onUpload,
                      icon: const Icon(Icons.upload_file),
                      label: const Text('Upload'),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                const Text(
                  'Prepare common files once, then use them when applying to scholarships.',
                  style: TextStyle(color: Color(0xFF64748B), height: 1.35),
                ),
                const SizedBox(height: 14),
                Row(
                  children: statItems
                      .map(
                        (item) => Expanded(
                          child: Padding(
                            padding: EdgeInsets.only(
                              right: item == statItems.last ? 0 : 8,
                            ),
                            child: _StatCard(data: item),
                          ),
                        ),
                      )
                      .toList(),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),
        Card(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Prepared files',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                ),
                const SizedBox(height: 12),
                if (preparedDocuments.isEmpty)
                  const Text(
                    'No prepared documents yet. Upload common requirements before applying.',
                    style: TextStyle(color: Color(0xFF64748B)),
                  )
                else
                  for (final document in preparedDocuments)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _PreparedDocumentRow(
                        document: document,
                        isSaving: isSaving,
                        onDelete: () => onDelete(document),
                      ),
                    ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),
        Card(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Application document status',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                ),
                const SizedBox(height: 12),
                if (applications.isEmpty)
                  const Text(
                    'Submitted applications will show document review status here.',
                    style: TextStyle(color: Color(0xFF64748B)),
                  )
                else
                  for (final application in applications)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _ApplicationDocumentStatus(
                        application: application,
                      ),
                    ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _PreparedDocumentRow extends StatelessWidget {
  const _PreparedDocumentRow({
    required this.document,
    required this.isSaving,
    required this.onDelete,
  });

  final Map<String, dynamic> document;
  final bool isSaving;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(14),
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
                Text(
                  stringValue(document['document_name']),
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
                const SizedBox(height: 3),
                Text(
                  '${stringValue(document['original_name'], fallback: 'Uploaded file')} - ${formatBytes(document['size'])}',
                  style: const TextStyle(color: Color(0xFF64748B)),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if (stringValue(document['uploaded_at']).isNotEmpty)
                  Text(
                    stringValue(document['uploaded_at']),
                    style: const TextStyle(
                      color: Color(0xFF94A3B8),
                      fontSize: 12,
                    ),
                  ),
              ],
            ),
          ),
          IconButton(
            onPressed: isSaving ? null : onDelete,
            icon: const Icon(Icons.delete_outline),
            color: const Color(0xFFB91C1C),
            tooltip: 'Remove',
          ),
        ],
      ),
    );
  }
}

class _ApplicationDocumentStatus extends StatelessWidget {
  const _ApplicationDocumentStatus({required this.application});

  final Map<String, dynamic> application;

  @override
  Widget build(BuildContext context) {
    final scholarship = asMap(application['scholarship']);
    final readiness = asMap(application['document_readiness']);
    final required = intValue(readiness['required']);
    final uploaded = intValue(readiness['uploaded']);
    final accepted = intValue(readiness['accepted']);
    final percent = intValue(readiness['uploaded_percent']);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            stringValue(scholarship['title'], fallback: 'Scholarship'),
            style: const TextStyle(fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(99),
            child: LinearProgressIndicator(
              value: required == 0 ? 1 : percent / 100,
              minHeight: 9,
              backgroundColor: const Color(0xFFE2E8F0),
              color: const Color(0xFF0284C7),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            required == 0
                ? 'No listed document requirements.'
                : '$uploaded of $required uploaded - $accepted accepted',
            style: const TextStyle(color: Color(0xFF475569)),
          ),
        ],
      ),
    );
  }
}

class _PreparedDocumentSheet extends StatefulWidget {
  const _PreparedDocumentSheet({
    required this.documentOptions,
    required this.apiClient,
  });

  final List<String> documentOptions;
  final ApiClient apiClient;

  @override
  State<_PreparedDocumentSheet> createState() => _PreparedDocumentSheetState();
}

class _PreparedDocumentSheetState extends State<_PreparedDocumentSheet> {
  String selectedDocument = '';
  PlatformFile? pickedFile;
  bool isUploading = false;

  @override
  void initState() {
    super.initState();
    selectedDocument = widget.documentOptions.isNotEmpty
        ? widget.documentOptions.first
        : '';
  }

  Future<void> pickDocumentFile() async {
    final result = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
    );

    if (result == null || result.files.isEmpty) {
      return;
    }

    setState(() {
      pickedFile = result.files.single;
    });
  }

  Future<void> upload() async {
    final filePath = pickedFile?.path;

    if (selectedDocument.isEmpty || filePath == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Choose a document type and file first.')),
      );
      return;
    }

    setState(() => isUploading = true);

    try {
      await widget.apiClient.uploadPreparedDocument(
        documentName: selectedDocument,
        filePath: filePath,
        fileName: pickedFile?.name,
      );

      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => isUploading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          bottom: MediaQuery.of(context).viewInsets.bottom + 20,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Upload prepared document',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            if (widget.documentOptions.isEmpty)
              const Text(
                'No document types are available yet.',
                style: TextStyle(color: Color(0xFF64748B)),
              )
            else
              DropdownButtonFormField<String>(
                initialValue: selectedDocument,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Document type',
                  border: OutlineInputBorder(),
                ),
                items: widget.documentOptions
                    .map(
                      (document) => DropdownMenuItem(
                        value: document,
                        child: Text(document, overflow: TextOverflow.ellipsis),
                      ),
                    )
                    .toList(),
                onChanged: (value) {
                  setState(() => selectedDocument = value ?? '');
                },
              ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: isUploading ? null : pickDocumentFile,
              icon: const Icon(Icons.attach_file),
              label: Text(
                pickedFile == null ? 'Choose file' : pickedFile!.name,
              ),
            ),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: isUploading ? null : upload,
              child: Text(isUploading ? 'Uploading...' : 'Upload document'),
            ),
          ],
        ),
      ),
    );
  }
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
      ..sort(
        (first, second) => intValue(
          asMap(second['eligibility_match'])['score'],
        ).compareTo(intValue(asMap(first['eligibility_match'])['score'])),
      );

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
              style: TextStyle(
                color: Color(0xFFFDE68A),
                fontSize: 16,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 12),
            if (top.isEmpty)
              const Text(
                'Published programs will appear here.',
                style: TextStyle(color: Colors.white70),
              )
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
            FilledButton.tonal(
              onPressed: onOpenPrograms,
              child: const Text('Browse programs'),
            ),
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
    required this.onView,
  });

  final Map<String, dynamic> scholarship;
  final bool isSaving;
  final bool alreadyApplied;
  final VoidCallback onSave;
  final VoidCallback onView;

  @override
  Widget build(BuildContext context) {
    final provider = asMap(scholarship['provider']);
    final match = asMap(scholarship['eligibility_match']);
    final requirements = documentRequirements(scholarship['requirements']);
    final score = intValue(match['score']);
    final distanceLabel = stringValue(scholarship['distance_label']);
    final educationLabel = criteriaLabel(
      scholarship['eligible_education_levels'],
    );
    final schoolTypeLabel = criteriaLabel(scholarship['eligible_school_types']);
    final quickNote = stringValue(
      match['summary'],
      fallback: stringValue(
        asMap(scholarship['eligibility_guide'])['note'],
        fallback:
            'Open the full page to review eligibility, documents, and map details.',
      ),
    );

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
                _ScholarshipLogo(
                  imageUrl: stringValue(scholarship['image_url']),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        stringValue(
                          provider['name'],
                          fallback: 'Scholarship Provider',
                        ),
                        style: const TextStyle(
                          color: Color(0xFF047857),
                          fontSize: 12,
                          fontWeight: FontWeight.w900,
                          letterSpacing: 1,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        stringValue(scholarship['title']),
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ],
                  ),
                ),
                _ScorePill(score: score, label: '$score%'),
              ],
            ),
            if (alreadyApplied) ...[
              const SizedBox(height: 8),
              const _StatusPill(label: 'Applied'),
            ],
            const SizedBox(height: 10),
            Text(
              stringValue(scholarship['description']),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _InfoChip(icon: Icons.school_outlined, label: educationLabel),
                _InfoChip(
                  icon: Icons.business_outlined,
                  label: schoolTypeLabel,
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _InfoChip(
                  icon: Icons.payments,
                  label: moneyValue(scholarship['award_amount']),
                ),
                _InfoChip(
                  icon: Icons.grade,
                  label:
                      'GWA ${stringValue(scholarship['minimum_gwa'], fallback: 'Any')}',
                ),
                _InfoChip(
                  icon: Icons.calendar_month,
                  label: stringValue(
                    scholarship['deadline'],
                    fallback: 'No deadline',
                  ),
                ),
                if (intValue(scholarship['slots_available']) > 0)
                  _InfoChip(
                    icon: Icons.groups_outlined,
                    label: '${intValue(scholarship['slots_available'])} slots',
                  ),
                if (stringValue(scholarship['application_mode']).isNotEmpty)
                  _InfoChip(
                    icon: Icons.assignment_turned_in_outlined,
                    label: applicationModeLabel(
                      scholarship['application_mode'],
                    ),
                  ),
                _InfoChip(
                  icon: Icons.description,
                  label: requirements.isEmpty
                      ? 'Docs not listed'
                      : '${requirements.length} docs',
                ),
                if (distanceLabel.isNotEmpty)
                  _InfoChip(icon: Icons.place_outlined, label: distanceLabel),
              ],
            ),
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Text(
                quickNote,
                style: const TextStyle(color: Color(0xFF475569), height: 1.35),
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: isSaving ? null : onSave,
                    icon: Icon(
                      scholarship['is_saved'] == true
                          ? Icons.bookmark
                          : Icons.bookmark_border,
                    ),
                    label: Text(
                      scholarship['is_saved'] == true ? 'Saved' : 'Save',
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    onPressed: onView,
                    child: const Text('View scholarship'),
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

class _ScholarshipDetailScreen extends StatefulWidget {
  const _ScholarshipDetailScreen({
    required this.scholarship,
    required this.apiClient,
    required this.alreadyApplied,
  });

  final Map<String, dynamic> scholarship;
  final ApiClient apiClient;
  final bool alreadyApplied;

  @override
  State<_ScholarshipDetailScreen> createState() =>
      _ScholarshipDetailScreenState();
}

class _ScholarshipDetailScreenState extends State<_ScholarshipDetailScreen> {
  late Map<String, dynamic> scholarship;
  late bool alreadyApplied;
  bool isSaving = false;

  @override
  void initState() {
    super.initState();
    scholarship = Map<String, dynamic>.from(widget.scholarship);
    alreadyApplied =
        widget.alreadyApplied || scholarship['has_applied'] == true;
  }

  Future<void> toggleSave() async {
    setState(() => isSaving = true);

    try {
      final id = intValue(scholarship['id']);
      final response = scholarship['is_saved'] == true
          ? await widget.apiClient.unsaveScholarship(id)
          : await widget.apiClient.saveScholarship(id);
      final updatedScholarship = asMap(response['scholarship']);

      if (mounted && updatedScholarship.isNotEmpty) {
        setState(() {
          scholarship = updatedScholarship;
          alreadyApplied = alreadyApplied || scholarship['has_applied'] == true;
        });
      }

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              stringValue(
                response['message'],
                fallback: 'Saved scholarships updated.',
              ),
            ),
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => isSaving = false);
      }
    }
  }

  Future<void> openApplicationWizard() async {
    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _ApplicationSheet(
        scholarship: scholarship,
        apiClient: widget.apiClient,
      ),
    );

    if (submitted == true && mounted) {
      setState(() => alreadyApplied = true);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Application submitted successfully.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final provider = asMap(scholarship['provider']);
    final match = asMap(scholarship['eligibility_match']);
    final criteria = asMapList(match['criteria']);
    final requirements = documentRequirements(scholarship['requirements']);
    final prepared = asMap(scholarship['prepared_documents']);
    final preparedMatched = stringList(prepared['matched']);
    final score = intValue(match['score']);
    final mapUrl = stringValue(scholarship['map_url']);
    final distanceLabel = stringValue(scholarship['distance_label']);
    final applicationMode = applicationModeLabel(
      scholarship['application_mode'],
    );

    return Scaffold(
      backgroundColor: const Color(0xFFE8EFF8),
      appBar: AppBar(
        title: const Text('Scholarship details'),
        backgroundColor: const Color(0xFF081426),
        foregroundColor: Colors.white,
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              color: const Color(0xFF081426),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _ScholarshipLogo(
                          imageUrl: stringValue(scholarship['image_url']),
                          size: 58,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                stringValue(
                                  scholarship['category'],
                                  fallback: labelFromKey(provider['type']),
                                ),
                                style: const TextStyle(
                                  color: Color(0xFFFDE68A),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w900,
                                  letterSpacing: 1.2,
                                ),
                              ),
                              const SizedBox(height: 10),
                              Text(
                                stringValue(
                                  scholarship['title'],
                                  fallback: 'Scholarship program',
                                ),
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 27,
                                  fontWeight: FontWeight.w900,
                                  height: 1.05,
                                ),
                              ),
                              const SizedBox(height: 12),
                              Text(
                                stringValue(
                                  provider['name'],
                                  fallback: 'Scholarship Provider',
                                ),
                                style: const TextStyle(
                                  color: Color(0xFFCBD5E1),
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _ScorePill(score: score, label: '$score% match'),
                        _InfoChip(
                          icon: Icons.calendar_month,
                          label: stringValue(
                            scholarship['deadline'],
                            fallback: 'No deadline',
                          ),
                        ),
                        if (distanceLabel.isNotEmpty)
                          _InfoChip(
                            icon: Icons.place_outlined,
                            label: distanceLabel,
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 14),
            _DetailSection(
              title: 'Program Overview',
              children: [
                Text(
                  stringValue(
                    scholarship['description'],
                    fallback: 'No description has been posted yet.',
                  ),
                  style: const TextStyle(height: 1.45),
                ),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _InfoChip(
                      icon: Icons.payments,
                      label: moneyValue(scholarship['award_amount']),
                    ),
                    _InfoChip(
                      icon: Icons.grade,
                      label:
                          'Minimum ${stringValue(scholarship['minimum_gwa'], fallback: 'Not listed')}',
                    ),
                    _InfoChip(
                      icon: Icons.description,
                      label: requirements.isEmpty
                          ? 'Docs not listed'
                          : '${requirements.length} documents',
                    ),
                    if (intValue(scholarship['slots_available']) > 0)
                      _InfoChip(
                        icon: Icons.groups_outlined,
                        label:
                            '${intValue(scholarship['slots_available'])} slots',
                      ),
                    if (stringValue(scholarship['application_mode']).isNotEmpty)
                      _InfoChip(
                        icon: Icons.assignment_turned_in_outlined,
                        label: applicationMode,
                      ),
                  ],
                ),
              ],
            ),
            _DetailSection(
              title: 'Application Workflow',
              children: [
                _DetailTile(
                  detail: _Detail('Application mode', applicationMode),
                ),
                const SizedBox(height: 8),
                _DetailTile(
                  detail: _Detail(
                    'Slots available',
                    intValue(scholarship['slots_available']) > 0
                        ? '${intValue(scholarship['slots_available'])}'
                        : 'Not listed',
                  ),
                ),
                const SizedBox(height: 8),
                _DetailTile(
                  detail: _Detail(
                    'Renewal policy',
                    stringValue(
                      scholarship['renewal_policy'],
                      fallback: 'Not listed',
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                _DetailTile(
                  detail: _Detail(
                    'Return service contract',
                    stringValue(
                      scholarship['return_service_contract'],
                      fallback: 'Not listed',
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                _DetailTile(
                  detail: _Detail(
                    'Other contract terms',
                    stringValue(
                      scholarship['other_contract_terms'],
                      fallback: 'Not listed',
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                _DetailTile(
                  detail: _Detail(
                    'Contact',
                    [
                          scholarship['contact_email'],
                          scholarship['contact_number'],
                        ]
                        .map(stringValue)
                        .where((value) => value.isNotEmpty)
                        .join(' / '),
                  ),
                ),
              ],
            ),
            _DetailSection(
              title: 'Eligibility',
              children: [
                Text(
                  stringValue(
                    scholarship['eligibility'],
                    fallback: 'No eligibility description has been posted yet.',
                  ),
                  style: const TextStyle(height: 1.45),
                ),
                const SizedBox(height: 14),
                _DetailTile(
                  detail: _Detail(
                    'Education levels',
                    criteriaLabel(scholarship['eligible_education_levels']),
                  ),
                ),
                _DetailTile(
                  detail: _Detail(
                    'School types',
                    criteriaLabel(scholarship['eligible_school_types']),
                  ),
                ),
                _DetailTile(
                  detail: _Detail(
                    'Tracks / strands / courses',
                    stringValue(
                      scholarship['eligible_courses'],
                      fallback: 'Any',
                    ),
                  ),
                ),
                _DetailTile(
                  detail: _Detail(
                    'Grade / year levels',
                    stringValue(
                      scholarship['eligible_year_levels'],
                      fallback: 'Any',
                    ),
                  ),
                ),
                _DetailTile(
                  detail: _Detail(
                    'Eligible locations',
                    stringValue(
                      scholarship['eligible_locations'],
                      fallback: 'Any',
                    ),
                  ),
                ),
                _DetailTile(
                  detail: _Detail(
                    'Income rule',
                    stringValue(
                      scholarship['income_requirement'],
                      fallback: 'Any',
                    ),
                  ),
                ),
              ],
            ),
            _DetailSection(
              title: 'Eligibility Pre-check',
              children: [
                Text(
                  stringValue(
                    match['summary'],
                    fallback: 'Review the listed requirements before applying.',
                  ),
                  style: const TextStyle(height: 1.45),
                ),
                if (criteria.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  for (final criterion in criteria)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: _CriterionTile(criterion: criterion),
                    ),
                ],
              ],
            ),
            _DetailSection(
              title: 'Document Requirements',
              children: [
                if (requirements.isEmpty)
                  const Text('No document requirements are listed yet.')
                else
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final requirement in requirements)
                        _RequirementChip(
                          label: requirement,
                          isReady: preparedMatched.contains(requirement),
                        ),
                    ],
                  ),
                if (requirements.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(
                    '${intValue(prepared['uploaded'])} of ${requirements.length} already uploaded in Documents.',
                    style: const TextStyle(
                      color: Color(0xFF475569),
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ],
            ),
            _DetailSection(
              title: 'Map Location',
              children: [
                Text(
                  stringValue(
                    scholarship['location_name'],
                    fallback: 'Location not named',
                  ),
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
                const SizedBox(height: 6),
                Text(
                  stringValue(
                    scholarship['location_address'],
                    fallback: 'No map address added yet.',
                  ),
                ),
                if (distanceLabel.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(
                    'About $distanceLabel from your saved location.',
                    style: const TextStyle(
                      color: Color(0xFF0369A1),
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
                if (mapUrl.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: () => openExternalMap(context, mapUrl),
                    icon: const Icon(Icons.map_outlined),
                    label: const Text('Open Maps'),
                  ),
                ],
              ],
            ),
            Card(
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(18),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    OutlinedButton.icon(
                      onPressed: isSaving ? null : toggleSave,
                      icon: Icon(
                        scholarship['is_saved'] == true
                            ? Icons.bookmark
                            : Icons.bookmark_border,
                      ),
                      label: Text(
                        isSaving
                            ? 'Saving...'
                            : scholarship['is_saved'] == true
                            ? 'Remove saved'
                            : 'Save scholarship',
                      ),
                    ),
                    const SizedBox(height: 10),
                    FilledButton(
                      onPressed: alreadyApplied ? null : openApplicationWizard,
                      child: Text(
                        alreadyApplied
                            ? 'Already applied'
                            : 'Start application wizard',
                      ),
                    ),
                  ],
                ),
              ),
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
    final dssCriteria = asMapList(dss['criteria']);
    final readiness = asMap(application['document_readiness']);
    final documents = asMapList(application['documents']);
    final timeline = asMapList(application['timeline']);
    final dssScore = intValue(application['dss_score']);
    final status = stringValue(application['status'], fallback: 'submitted');
    final hasOutcome =
        stringValue(application['awarded_amount']).isNotEmpty ||
        stringValue(application['distribution_scheduled_for']).isNotEmpty ||
        stringValue(application['distribution_instructions']).isNotEmpty ||
        [
          'approved',
          'awarded',
          'distribution_scheduled',
          'disbursed',
          'renewed',
        ].contains(status);

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
                        stringValue(
                          scholarship['title'],
                          fallback: 'Scholarship',
                        ),
                        style: const TextStyle(
                          fontSize: 19,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Submitted ${stringValue(application['submitted_at'], fallback: 'recently')}',
                      ),
                    ],
                  ),
                ),
                _StatusPill(label: labelFromKey(status), status: status),
              ],
            ),
            const SizedBox(height: 14),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Text(
                statusDescription(status),
                style: const TextStyle(color: Color(0xFF475569), height: 1.35),
              ),
            ),
            const SizedBox(height: 12),
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
                          stringValue(
                            dss['label'],
                            fallback: labelFromKey(
                              application['dss_recommendation'],
                            ),
                          ),
                          style: const TextStyle(
                            color: Color(0xFF312E81),
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                      _ScorePill(score: dssScore, label: '$dssScore% DSS'),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    stringValue(
                      dss['summary'],
                      fallback: 'DSS helps providers prioritize applications.',
                    ),
                  ),
                  if (dssCriteria.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    for (final criterion in dssCriteria)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: _DssCriterionRow(criterion: criterion),
                      ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _InfoChip(
                  icon: Icons.checklist,
                  label:
                      '${intValue(readiness['confirmed'])}/${intValue(readiness['required'])} confirmed',
                ),
                _InfoChip(
                  icon: Icons.upload_file,
                  label: '${intValue(readiness['uploaded'])} uploaded',
                ),
                _InfoChip(
                  icon: Icons.verified,
                  label: '${intValue(readiness['accepted'])} accepted',
                ),
              ],
            ),
            if (hasOutcome) ...[
              const SizedBox(height: 12),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFECFDF5),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: const Color(0xFFA7F3D0)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Reward distribution',
                      style: TextStyle(
                        color: Color(0xFF065F46),
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _InfoChip(
                          icon: Icons.payments_outlined,
                          label: stringValue(
                            application['awarded_amount'],
                            fallback: 'Amount not listed',
                          ),
                        ),
                        _InfoChip(
                          icon: Icons.event_available_outlined,
                          label: stringValue(
                            application['distribution_scheduled_for'],
                            fallback: 'Provider will set the date',
                          ),
                        ),
                        _InfoChip(
                          icon: Icons.flag_outlined,
                          label: labelFromKey(status),
                        ),
                      ],
                    ),
                    if (stringValue(
                      application['distribution_instructions'],
                    ).isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text(
                          stringValue(application['distribution_instructions']),
                          style: const TextStyle(color: Color(0xFF166534)),
                        ),
                      ),
                  ],
                ),
              ),
            ],
            if (documents.isNotEmpty) ...[
              const SizedBox(height: 12),
              const Text(
                'Documents',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              for (final document in documents)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: _DocumentRow(document: document),
                ),
            ],
            if (timeline.isNotEmpty) ...[
              const SizedBox(height: 12),
              const Text(
                'Timeline',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              for (final event in timeline.take(3))
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    '${labelFromKey(event['to_status'])} - ${stringValue(event['changed_at'], fallback: 'recently')}',
                  ),
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

class _DssCriterionRow extends StatelessWidget {
  const _DssCriterionRow({required this.criterion});

  final Map<String, dynamic> criterion;

  @override
  Widget build(BuildContext context) {
    final score = intValue(criterion['score']);
    final weight = intValue(criterion['weight']);

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.white.withAlpha(210),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFC7D2FE)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  stringValue(criterion['label'], fallback: 'DSS factor'),
                  style: const TextStyle(
                    color: Color(0xFF312E81),
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              Text(
                '$score% / $weight%',
                style: const TextStyle(
                  color: Color(0xFF4338CA),
                  fontWeight: FontWeight.w900,
                  fontSize: 12,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(99),
            child: LinearProgressIndicator(
              value: score / 100,
              minHeight: 7,
              backgroundColor: const Color(0xFFE0E7FF),
              color: const Color(0xFF4F46E5),
            ),
          ),
          if (stringValue(criterion['note']).isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              stringValue(criterion['note']),
              style: const TextStyle(color: Color(0xFF475569), fontSize: 12),
            ),
          ],
        ],
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
                  style: TextStyle(
                    color: colors.text,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              Text(
                labelFromKey(status),
                style: TextStyle(
                  color: colors.text,
                  fontSize: 12,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text('Profile: $studentValue', style: TextStyle(color: colors.text)),
          if (stringValue(criterion['requirement']).isNotEmpty)
            Text(
              'Required: ${stringValue(criterion['requirement'])}',
              style: TextStyle(color: colors.text),
            ),
          if (stringValue(criterion['note']).isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              stringValue(criterion['note']),
              style: TextStyle(color: colors.text.withAlpha(210), fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }
}

class _ApplicationSheet extends StatefulWidget {
  const _ApplicationSheet({required this.scholarship, required this.apiClient});

  final Map<String, dynamic> scholarship;
  final ApiClient apiClient;

  @override
  State<_ApplicationSheet> createState() => _ApplicationSheetState();
}

class _ApplicationSheetState extends State<_ApplicationSheet> {
  final notesController = TextEditingController();
  late final List<String> requirements;
  late final List<String> preparedMatches;
  final Set<String> selected = {};
  bool isSubmitting = false;

  @override
  void initState() {
    super.initState();
    requirements = documentRequirements(widget.scholarship['requirements']);
    preparedMatches = stringList(
      asMap(widget.scholarship['prepared_documents'])['matched'],
    );
    selected.addAll(preparedMatches);
  }

  @override
  void dispose() {
    notesController.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (requirements.isNotEmpty && selected.length != requirements.length) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Confirm all listed documents before submitting.'),
        ),
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
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
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
            const Text(
              'Confirm the requirements you have prepared. Files uploaded in the Documents tab are attached automatically when available.',
            ),
            const SizedBox(height: 16),
            if (requirements.isNotEmpty)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFEEF2FF),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: const Color(0xFFC7D2FE)),
                ),
                child: Text(
                  '${preparedMatches.length} of ${requirements.length} requirements already match your Documents wallet.',
                  style: const TextStyle(
                    color: Color(0xFF3730A3),
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            if (requirements.isNotEmpty) const SizedBox(height: 12),
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
                  subtitle: Text(
                    preparedMatches.contains(requirement)
                        ? 'Uploaded in Documents and will be attached.'
                        : 'Confirm manually or upload it in Documents first.',
                  ),
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
              child: Text(
                isSubmitting ? 'Submitting...' : 'Submit application',
              ),
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
    _ProfileField('middle_initial', 'M.I.', maxLength: 1),
    _ProfileField('last_name', 'Last name', required: true),
    _ProfileField('suffix', 'Suffix', maxLength: 20),
    _ProfileField(
      'gender',
      'Gender (female / male / non_binary / prefer_not_to_say)',
    ),
    _ProfileField(
      'contact_number',
      'Contact number',
      required: true,
      keyboardType: TextInputType.phone,
    ),
    _ProfileField(
      'education_level',
      'Education level (elementary / junior_high_school / senior_high_school / college)',
    ),
    _ProfileField('school', 'School / learning institution'),
    _ProfileField('school_type', 'School type'),
    _ProfileField('learner_reference_number', 'LRN / student number'),
    _ProfileField('course_or_strand', 'Track / strand / course'),
    _ProfileField('year_level', 'Grade / year level'),
    _ProfileField('enrollment_status', 'Enrollment status'),
    _ProfileField(
      'gwa',
      'GWA / general average',
      keyboardType: TextInputType.number,
    ),
    _ProfileField('grading_scale', 'Grading scale (percentage / grade_point)'),
    _ProfileField('income_bracket', 'Income bracket'),
    _ProfileField(
      'household_size',
      'Household size',
      keyboardType: TextInputType.number,
    ),
    _ProfileField('preferred_categories', 'Preferred categories', maxLines: 2),
    _ProfileField(
      'preferred_locations',
      'Preferred scholarship locations',
      maxLines: 2,
    ),
    _ProfileField(
      'willing_to_relocate',
      'Willing to relocate (yes / no / depends)',
    ),
    _ProfileField('support_needs', 'Support needs', maxLines: 3),
    _ProfileField('scholarship_goal', 'Scholarship goal', maxLines: 3),
    _ProfileField('barangay', 'Barangay'),
    _ProfileField('city', 'City / municipality'),
    _ProfileField('province', 'Province'),
    _ProfileField('region', 'Region'),
    _ProfileField('latitude', 'Latitude', keyboardType: TextInputType.number),
    _ProfileField('longitude', 'Longitude', keyboardType: TextInputType.number),
    _ProfileField('address', 'Address'),
    _ProfileField('birthdate', 'Birthdate YYYY-MM-DD'),
    _ProfileField('guardian_name', 'Guardian name'),
    _ProfileField(
      'guardian_contact',
      'Guardian contact',
      keyboardType: TextInputType.phone,
    ),
  ];

  @override
  void initState() {
    super.initState();
    for (final field in fields) {
      controllers[field.key] = TextEditingController(
        text: stringValue(widget.user[field.key]),
      );
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
      payload['grading_scale'] = normalizeGradingScale(
        payload['grading_scale'],
      );
      payload['willing_to_relocate'] = normalizeRelocationChoice(
        payload['willing_to_relocate'],
      );
      payload['gender'] = normalizeGenderChoice(payload['gender']);

      await widget.apiClient.updateProfile(payload);

      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
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
                    maxLines: field.maxLines,
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

                      if (field.key == 'middle_initial' &&
                          !RegExp(r'^[A-Za-z]$').hasMatch(stringValue(value))) {
                        return 'Use 1 letter';
                      }

                      if (field.key == 'willing_to_relocate') {
                        final normalized = normalizeRelocationChoice(value);
                        if (stringValue(value).isNotEmpty &&
                            normalized.isEmpty) {
                          return 'Use yes, no, or depends';
                        }
                      }

                      if (field.key == 'gender') {
                        final normalized = normalizeGenderChoice(value);
                        if (stringValue(value).isNotEmpty &&
                            normalized.isEmpty) {
                          return 'Use female, male, non_binary, or prefer_not_to_say';
                        }
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
    this.maxLines = 1,
    this.keyboardType,
  });

  final String key;
  final String label;
  final bool required;
  final int? maxLength;
  final int maxLines;
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
            style: const TextStyle(
              color: Color(0xFF64748B),
              fontSize: 12,
              fontWeight: FontWeight.w800,
            ),
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

class _DetailSection extends StatelessWidget {
  const _DetailSection({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            ...children,
          ],
        ),
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

class _ScholarshipLogo extends StatelessWidget {
  const _ScholarshipLogo({required this.imageUrl, this.size = 52});

  final String imageUrl;
  final double size;

  @override
  Widget build(BuildContext context) {
    final safeImageUrl = mobileImageUrl(imageUrl);

    return Container(
      width: size,
      height: size,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(10),
        child: safeImageUrl.isEmpty
            ? const Icon(Icons.school_outlined, color: Color(0xFF64748B))
            : Image.network(
                safeImageUrl,
                fit: BoxFit.cover,
                errorBuilder: (context, error, stackTrace) =>
                    const Icon(Icons.school_outlined, color: Color(0xFF64748B)),
              ),
      ),
    );
  }
}

class _RequirementChip extends StatelessWidget {
  const _RequirementChip({required this.label, required this.isReady});

  final String label;
  final bool isReady;

  @override
  Widget build(BuildContext context) {
    return Chip(
      avatar: Icon(
        isReady ? Icons.check_circle : Icons.pending_outlined,
        color: isReady ? const Color(0xFF047857) : const Color(0xFF92400E),
        size: 16,
      ),
      label: Text(label),
      backgroundColor: isReady
          ? const Color(0xFFECFDF5)
          : const Color(0xFFFFFBEB),
      side: BorderSide(
        color: isReady ? const Color(0xFFA7F3D0) : const Color(0xFFFDE68A),
      ),
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
  const _StatusPill({required this.label, this.status});

  final String label;
  final String? status;

  @override
  Widget build(BuildContext context) {
    final raw = stringValue(status ?? label).toLowerCase().replaceAll(' ', '_');
    final isPositive = [
      'approved',
      'qualified',
      'awarded',
      'disbursed',
      'renewed',
    ].contains(raw);
    final isClosed = ['rejected', 'not_awarded'].contains(raw);
    final isReview = [
      'under_review',
      'shortlisted',
      'interview',
      'distribution_scheduled',
    ].contains(raw);
    final background = isPositive
        ? const Color(0xFFDCFCE7)
        : isClosed
        ? const Color(0xFFFFE4E6)
        : isReview
        ? const Color(0xFFE0F2FE)
        : const Color(0xFFFEF3C7);
    final text = isPositive
        ? const Color(0xFF166534)
        : isClosed
        ? const Color(0xFF9F1239)
        : isReview
        ? const Color(0xFF075985)
        : const Color(0xFF92400E);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        label,
        style: TextStyle(color: text, fontWeight: FontWeight.w900),
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
                Text(
                  stringValue(document['document_name']),
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
                Text(
                  labelFromKey(document['status']),
                  style: const TextStyle(color: Color(0xFF64748B)),
                ),
                if (stringValue(document['review_notes']).isNotEmpty)
                  Text(
                    stringValue(document['review_notes']),
                    style: const TextStyle(color: Color(0xFF92400E)),
                  ),
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

List<String> stringList(Object? value) {
  if (value is List) {
    return value.map(stringValue).where((item) => item.isNotEmpty).toList();
  }

  return [];
}

List<Map<String, dynamic>> rankedScholarships(
  List<Map<String, dynamic>> scholarships,
) {
  return [...scholarships]..sort(
    (first, second) => intValue(
      asMap(second['eligibility_match'])['score'],
    ).compareTo(intValue(asMap(first['eligibility_match'])['score'])),
  );
}

List<Map<String, dynamic>> filterScholarships({
  required List<Map<String, dynamic>> scholarships,
  required String search,
  required String category,
  required String providerType,
  required String educationLevel,
  required String schoolType,
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
    final locationText = [
      scholarship['eligible_locations'],
      scholarship['location_name'],
      scholarship['location_address'],
    ].map(stringValue).where((value) => value.isNotEmpty).join(' ');
    final matchesSearch =
        keyword.isEmpty ||
        [
          scholarship['title'],
          scholarship['description'],
          scholarship['category'],
          scholarship['eligibility'],
          scholarship['eligible_education_levels'],
          scholarship['eligible_courses'],
          scholarship['eligible_school_types'],
          scholarship['eligible_year_levels'],
          scholarship['income_requirement'],
          scholarship['requirements'],
          provider['name'],
        ].any((value) => stringValue(value).toLowerCase().contains(keyword)) ||
        locationKeywordMatches(locationText, keyword);

    return matchesSearch &&
        optionMatches(scholarship['category'], category) &&
        optionMatches(provider['type'], providerType) &&
        textMatches(scholarship['eligible_education_levels'], educationLevel) &&
        textMatches(scholarship['eligible_school_types'], schoolType) &&
        textMatches(scholarship['income_requirement'], incomeRule) &&
        deadlineMatches(scholarship, deadline) &&
        (minimum == null || score >= minimum) &&
        (!savedOnly || scholarship['is_saved'] == true);
  }).toList();

  return rankedScholarships(filtered)..sort((first, second) {
    final scoreDifference = intValue(
      asMap(second['eligibility_match'])['score'],
    ).compareTo(intValue(asMap(first['eligibility_match'])['score']));

    if (scoreDifference != 0) {
      return scoreDifference;
    }

    return deadlineSortValue(first).compareTo(deadlineSortValue(second));
  });
}

List<String> filterOptions(
  List<Map<String, dynamic>> scholarships,
  String Function(Map<String, dynamic>) selector,
) {
  final options =
      scholarships
          .map(selector)
          .where((value) => value.trim().isNotEmpty)
          .toSet()
          .toList()
        ..sort();

  return ['all', ...options];
}

List<String> criteriaFilterOptions(
  List<Map<String, dynamic>> scholarships,
  String field,
) {
  final options =
      scholarships
          .expand((scholarship) => splitCriteriaOptions(scholarship[field]))
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

List<String> splitCriteriaOptions(Object? value) {
  final text = stringValue(value);

  if (text.isEmpty) {
    return [];
  }

  return text
      .split(RegExp(r'\r?\n|,'))
      .map((item) => item.trim())
      .where((item) => item.isNotEmpty)
      .toList();
}

String criteriaLabel(Object? value, {String fallback = 'Open to all'}) {
  final items = splitCriteriaOptions(value).map(labelFromKey).toList();

  return items.isEmpty ? fallback : items.join(', ');
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

  return haystack.isEmpty ||
      haystack.contains(needle) ||
      needle.contains(haystack);
}

String normalizeLocationText(Object? value) {
  return stringValue(value)
      .toLowerCase()
      .replaceAll(RegExp(r'[.;:]'), '')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();
}

bool isOpenPhilippineLocationText(Object? value) {
  final normalized = normalizeLocationText(value);

  if (normalized.isEmpty) {
    return false;
  }

  return {
        'all locations',
        'any location',
        'all regions',
        'any region',
        'nationwide',
        'philippines',
        'the philippines',
        'republic of the philippines',
        'nationwide philippines',
        'philippines nationwide',
        'anywhere in the philippines',
        'within the philippines',
        'all over the philippines',
        'all philippines',
      }.contains(normalized) ||
      normalized.contains('open to all') ||
      normalized.contains('no restriction') ||
      (normalized.contains('nationwide') &&
          !normalized.contains('not nationwide'));
}

bool locationKeywordMatches(Object? value, String keyword) {
  final needle = normalizeLocationText(keyword);
  final haystack = normalizeLocationText(value);

  if (needle.isEmpty || haystack.isEmpty) {
    return false;
  }

  if (isOpenPhilippineLocationText(needle) &&
      isOpenPhilippineLocationText(haystack)) {
    return true;
  }

  return haystack.contains(needle) || needle.contains(haystack);
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

  final match = RegExp(
    r'^([A-Za-z]{3}) (\d{1,2}), (\d{4})$',
  ).firstMatch(value.trim());

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
  final key = stringValue(value, fallback: 'pending');

  if (key == 'disbursed') {
    return 'Distributed';
  }

  final text = key.replaceAll('_', ' ');
  return text
      .split(' ')
      .where((part) => part.isNotEmpty)
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .join(' ');
}

String profileLabelFromKey(Object? value) {
  final text = stringValue(value);

  return text.isEmpty ? 'Not provided' : labelFromKey(text);
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

String applicationModeLabel(Object? value) {
  final mode = stringValue(value);

  if (mode.isEmpty) {
    return 'Not listed';
  }

  return labelFromKey(mode);
}

String statusDescription(Object? value) {
  final status = stringValue(value, fallback: 'submitted');

  return switch (status) {
    'submitted' =>
      'Your application was received and is waiting for provider review.',
    'under_review' =>
      'The provider is checking your details and uploaded documents.',
    'qualified' =>
      'Your application passed initial requirements and remains in review.',
    'shortlisted' => 'You are in a smaller candidate pool for closer review.',
    'interview' =>
      'The provider may contact you for interview or follow-up requirements.',
    'approved' => 'Your application was approved by the provider.',
    'awarded' =>
      'The provider recorded your award and will set the reward distribution schedule.',
    'distribution_scheduled' =>
      'The provider scheduled your reward distribution. Review the date and instructions.',
    'disbursed' => 'The provider marked the scholarship reward as distributed.',
    'renewed' => 'This scholarship support was renewed.',
    'rejected' => 'The application was closed and not approved.',
    'not_awarded' => 'The application completed review but was not awarded.',
    _ => 'The provider will update this status as review progresses.',
  };
}

String mobileImageUrl(Object? value) {
  final url = stringValue(value);
  final source = Uri.tryParse(url);
  final target = Uri.tryParse(ApiClient.assetBaseUrl);

  if (source == null || target == null) {
    return url;
  }

  if (['127.0.0.1', 'localhost'].contains(source.host)) {
    return source
        .replace(scheme: target.scheme, host: target.host, port: target.port)
        .toString();
  }

  return url;
}

String coordinateLabel(Object? latitude, Object? longitude) {
  final lat = stringValue(latitude);
  final lng = stringValue(longitude);

  if (lat.isEmpty || lng.isEmpty) {
    return 'Not provided';
  }

  return '$lat, $lng';
}

Future<void> openExternalMap(BuildContext context, String url) async {
  final uri = Uri.tryParse(url);

  if (uri == null) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('Map link is not valid.')));
    return;
  }

  final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);

  if (!opened && context.mounted) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('Unable to open map link.')));
  }
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

String normalizeRelocationChoice(Object? value) {
  final choice = stringValue(value).toLowerCase().trim();

  if (choice.isEmpty) {
    return '';
  }

  if (choice == 'yes' || choice == 'no' || choice == 'depends') {
    return choice;
  }

  return '';
}

String normalizeGenderChoice(Object? value) {
  final choice = stringValue(
    value,
  ).toLowerCase().trim().replaceAll(RegExp(r'[\s-]+'), '_');

  if (choice.isEmpty) {
    return '';
  }

  if (choice == 'female' || choice == 'male' || choice == 'non_binary') {
    return choice;
  }

  if (choice == 'prefer_not_to_say' || choice == 'rather_not_say') {
    return 'prefer_not_to_say';
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

String formatBytes(Object? value) {
  final bytes = double.tryParse(stringValue(value));

  if (bytes == null || bytes <= 0) {
    return 'Size unknown';
  }

  if (bytes >= 1024 * 1024) {
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  if (bytes >= 1024) {
    return '${(bytes / 1024).toStringAsFixed(1)} KB';
  }

  return '${bytes.toStringAsFixed(0)} B';
}

List<String> documentRequirements(Object? value) {
  return stringValue(value)
      .split(RegExp(r'\r?\n|,'))
      .map((requirement) => requirement.trim())
      .where((requirement) => requirement.isNotEmpty)
      .toList();
}
