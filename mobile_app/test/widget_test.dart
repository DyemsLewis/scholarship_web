import 'package:flutter_test/flutter_test.dart';
import 'package:mobile_app/main.dart';
import 'package:mobile_app/services/api_client.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  testWidgets('shows applicant auth screen', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});

    final apiClient = ApiClient();
    await apiClient.init();

    await tester.pumpWidget(ScholarshipMobileApp(apiClient: apiClient));

    expect(find.text('Scholarship Portal'), findsOneWidget);
    expect(find.text('Log in to portal'), findsOneWidget);
    expect(find.text('Register'), findsOneWidget);
  });
}
