import 'package:flutter/material.dart';

import '../services/api_client.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({
    required this.apiClient,
    required this.onAuthenticated,
    super.key,
  });

  final ApiClient apiClient;
  final VoidCallback onAuthenticated;

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final formKey = GlobalKey<FormState>();
  final emailController = TextEditingController();
  final passwordController = TextEditingController();
  final confirmPasswordController = TextEditingController();
  final firstNameController = TextEditingController();
  final middleInitialController = TextEditingController();
  final lastNameController = TextEditingController();
  final usernameController = TextEditingController();
  final contactNumberController = TextEditingController();

  bool isLogin = true;
  bool isSubmitting = false;

  @override
  void dispose() {
    emailController.dispose();
    passwordController.dispose();
    confirmPasswordController.dispose();
    firstNameController.dispose();
    middleInitialController.dispose();
    lastNameController.dispose();
    usernameController.dispose();
    contactNumberController.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!formKey.currentState!.validate()) {
      return;
    }

    setState(() {
      isSubmitting = true;
    });

    try {
      if (isLogin) {
        await widget.apiClient.login(
          email: emailController.text.trim(),
          password: passwordController.text,
        );
      } else {
        await widget.apiClient.register(
          firstName: firstNameController.text.trim(),
          lastName: lastNameController.text.trim(),
          middleInitial: middleInitialController.text.trim().toUpperCase(),
          email: emailController.text.trim(),
          username: usernameController.text.trim(),
          contactNumber: contactNumberController.text.trim(),
          password: passwordController.text,
          passwordConfirmation: confirmPasswordController.text,
        );
      }

      widget.onAuthenticated();
    } on ApiException catch (error) {
      showMessage(error.message);
    } catch (_) {
      showMessage('Unable to connect to the scholarship portal.');
    } finally {
      if (mounted) {
        setState(() {
          isSubmitting = false;
        });
      }
    }
  }

  void showMessage(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  void switchMode(bool loginMode) {
    setState(() {
      isLogin = loginMode;
    });
    formKey.currentState?.reset();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFF081426), Color(0xFF10223A), Color(0xFFE8EFF8)],
            stops: [0, 0.48, 1],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 520),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const _HeroHeader(),
                    const SizedBox(height: 22),
                    Card(
                      elevation: 18,
                      shadowColor: Colors.black26,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(18),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(20),
                        child: Form(
                          key: formKey,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _ModeSwitch(
                                isLogin: isLogin,
                                onChanged: switchMode,
                              ),
                              const SizedBox(height: 18),
                              if (!isLogin) ...[
                                _TextField(
                                  controller: firstNameController,
                                  label: 'First name',
                                  validator: requiredValidator,
                                ),
                                const SizedBox(height: 12),
                                Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Expanded(
                                      child: _TextField(
                                        controller: middleInitialController,
                                        label: 'M.I.',
                                        textCapitalization:
                                            TextCapitalization.characters,
                                        maxLength: 1,
                                        validator: middleInitialValidator,
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      flex: 3,
                                      child: _TextField(
                                        controller: lastNameController,
                                        label: 'Last name',
                                        validator: requiredValidator,
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 12),
                                _TextField(
                                  controller: usernameController,
                                  label: 'Username',
                                  validator: usernameValidator,
                                ),
                                const SizedBox(height: 12),
                                _TextField(
                                  controller: contactNumberController,
                                  label: 'Contact number',
                                  keyboardType: TextInputType.phone,
                                  validator: contactValidator,
                                ),
                                const SizedBox(height: 12),
                              ],
                              _TextField(
                                controller: emailController,
                                label: 'Email address',
                                keyboardType: TextInputType.emailAddress,
                                validator: emailValidator,
                              ),
                              const SizedBox(height: 12),
                              _TextField(
                                controller: passwordController,
                                label: 'Password',
                                obscureText: true,
                                validator: passwordValidator,
                              ),
                              if (!isLogin) ...[
                                const SizedBox(height: 12),
                                _TextField(
                                  controller: confirmPasswordController,
                                  label: 'Confirm password',
                                  obscureText: true,
                                  validator: confirmPasswordValidator,
                                ),
                              ],
                              const SizedBox(height: 18),
                              FilledButton(
                                onPressed: isSubmitting ? null : submit,
                                style: FilledButton.styleFrom(
                                  backgroundColor: const Color(0xFF081426),
                                  foregroundColor: Colors.white,
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 15,
                                  ),
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                ),
                                child: Text(
                                  isSubmitting
                                      ? 'Please wait...'
                                      : isLogin
                                      ? 'Log in to portal'
                                      : 'Create applicant account',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  String? requiredValidator(String? value) {
    return value == null || value.trim().isEmpty
        ? 'This field is required.'
        : null;
  }

  String? emailValidator(String? value) {
    if (value == null || value.trim().isEmpty) {
      return 'Email is required.';
    }

    return value.contains('@') ? null : 'Enter a valid email address.';
  }

  String? passwordValidator(String? value) {
    if (value == null || value.length < 8) {
      return 'Password must be at least 8 characters.';
    }

    return null;
  }

  String? confirmPasswordValidator(String? value) {
    if (value != passwordController.text) {
      return 'Passwords must match.';
    }

    return null;
  }

  String? middleInitialValidator(String? value) {
    final text = value?.trim() ?? '';
    return RegExp(r'^[A-Za-z]$').hasMatch(text) ? null : 'Use 1 letter.';
  }

  String? usernameValidator(String? value) {
    final text = value?.trim() ?? '';
    return RegExp(r'^[A-Za-z0-9_.-]{4,}$').hasMatch(text)
        ? null
        : 'Use at least 4 letters or numbers.';
  }

  String? contactValidator(String? value) {
    final digits = (value ?? '').replaceAll(RegExp(r'\D'), '');
    return digits.length >= 10 ? null : 'Enter at least 10 digits.';
  }
}

class _HeroHeader extends StatelessWidget {
  const _HeroHeader();

  @override
  Widget build(BuildContext context) {
    return const Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'SCHOLARSHIP ACCESS',
          style: TextStyle(
            color: Color(0xFFFDE68A),
            fontSize: 12,
            fontWeight: FontWeight.w800,
            letterSpacing: 2.4,
          ),
        ),
        SizedBox(height: 12),
        Text(
          'Scholarship Portal',
          style: TextStyle(
            color: Colors.white,
            fontSize: 36,
            fontWeight: FontWeight.w900,
            height: 1.05,
          ),
        ),
        SizedBox(height: 12),
        Text(
          'Applicant-only mobile access for scholarship profiles, updates, and future applications.',
          style: TextStyle(
            color: Color(0xFFE2E8F0),
            fontSize: 16,
            height: 1.55,
          ),
        ),
      ],
    );
  }
}

class _ModeSwitch extends StatelessWidget {
  const _ModeSwitch({required this.isLogin, required this.onChanged});

  final bool isLogin;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          _ModeButton(
            label: 'Login',
            selected: isLogin,
            onTap: () => onChanged(true),
          ),
          _ModeButton(
            label: 'Register',
            selected: !isLogin,
            onTap: () => onChanged(false),
          ),
        ],
      ),
    );
  }
}

class _ModeButton extends StatelessWidget {
  const _ModeButton({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(9),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.symmetric(vertical: 11),
          decoration: BoxDecoration(
            color: selected ? Colors.white : Colors.transparent,
            borderRadius: BorderRadius.circular(9),
            boxShadow: selected
                ? [
                    BoxShadow(
                      color: Colors.black.withAlpha(20),
                      blurRadius: 16,
                      offset: const Offset(0, 8),
                    ),
                  ]
                : null,
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: selected
                  ? const Color(0xFF081426)
                  : const Color(0xFF64748B),
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      ),
    );
  }
}

class _TextField extends StatelessWidget {
  const _TextField({
    required this.controller,
    required this.label,
    this.validator,
    this.keyboardType,
    this.obscureText = false,
    this.textCapitalization = TextCapitalization.none,
    this.maxLength,
  });

  final TextEditingController controller;
  final String label;
  final String? Function(String?)? validator;
  final TextInputType? keyboardType;
  final bool obscureText;
  final TextCapitalization textCapitalization;
  final int? maxLength;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      keyboardType: keyboardType,
      obscureText: obscureText,
      validator: validator,
      textCapitalization: textCapitalization,
      maxLength: maxLength,
      decoration: InputDecoration(
        labelText: label,
        counterText: '',
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: Color(0xFF0284C7), width: 1.5),
        ),
      ),
    );
  }
}
