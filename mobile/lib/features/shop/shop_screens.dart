import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

String _money(BuildContext context, num v) => '${v.toStringAsFixed(2)} ${context.tr('shop.qar')}';

/// The cart: lines with their seat holds, a discount code, totals, and payment on the Ministry gateway (opened in the browser).
class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  final _code = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  void _refresh() => ref.invalidate(getProvider('/me/cart'));

  Future<void> _call(Future<dynamic> Function() fn) async {
    setState(() => _busy = true);
    try {
      await fn();
      _refresh();
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkout() async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiProvider).post('/me/checkout');
      final data = Map<String, dynamic>.from((res as Map)['data'] as Map);
      final url = data.str('redirect_url');
      _refresh();
      if (url.isNotEmpty) {
        final uri = Uri.tryParse(url);
        if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else if (mounted) {
        showSnack(context, context.tr('shop.done'));
        ref.invalidate(getProvider('/me/registrations'));
      }
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cart = ref.watch(getProvider('/me/cart'));
    final ar = context.s.isArabic;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('shop.cart'))),
      body: AsyncView(
        value: cart,
        onRetry: _refresh,
        builder: (raw) {
          final c = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final lines = c.list('lines');
          if (lines.isEmpty) return EmptyView(text: context.tr('shop.empty'));
          return ListView(padding: const EdgeInsets.all(16), children: [
            for (final l in lines)
              Card(
                child: ListTile(
                  title: Text(ar ? l.str('title_ar') : l.str('title_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(l.number('unit_price') > 0 ? _money(context, l.number('unit_price')) : context.tr('shop.free')),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    Text(_money(context, l.number('total')), style: const TextStyle(fontWeight: FontWeight.w800)),
                    IconButton(onPressed: _busy ? null : () => _call(() => ref.read(apiProvider).delete('/me/cart/items/${l.str('group_id')}')), icon: const Icon(Icons.delete_outline)),
                  ]),
                ),
              ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: TextField(controller: _code, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: context.tr('shop.code')))),
              const SizedBox(width: 8),
              OutlinedButton(onPressed: _busy ? null : () => _call(() => ref.read(apiProvider).post('/me/cart/discount', {'code': _code.text.trim().isEmpty ? null : _code.text.trim()})), child: Text(context.tr('shop.apply'))),
            ]),
            const SizedBox(height: 16),
            _row(context, context.tr('shop.subtotal'), _money(context, c.number('subtotal'))),
            if (c.number('discount') > 0) _row(context, context.tr('shop.discount'), '- ${_money(context, c.number('discount'))}'),
            if (c.number('vat') > 0) _row(context, context.tr('shop.vat'), _money(context, c.number('vat'))),
            _row(context, context.tr('shop.total'), _money(context, c.number('total')), bold: true),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _busy ? null : _checkout,
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size.fromHeight(52)),
              child: Text(context.tr(c.number('total') > 0 ? 'shop.pay' : 'shop.confirmFree')),
            ),
            const SizedBox(height: 8),
            Text(context.tr('shop.secure'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]);
        },
      ),
    );
  }

  Widget _row(BuildContext context, String label, String value, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(label, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w400)),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w900 : FontWeight.w600, fontSize: bold ? 18 : 14)),
        ]),
      );
}

/// My orders, my vouchers, and a box to redeem a voucher code.
class OrdersScreen extends ConsumerStatefulWidget {
  const OrdersScreen({super.key});

  @override
  ConsumerState<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends ConsumerState<OrdersScreen> {
  final _code = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _redeem() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/me/vouchers/redeem', {'code': _code.text.trim()});
      _code.clear();
      ref.invalidate(getProvider('/me/vouchers'));
      ref.invalidate(getProvider('/me/registrations'));
      if (mounted) showSnack(context, context.tr('shop.redeemed'));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final orders = ref.watch(getProvider('/me/orders'));
    final vouchers = ref.watch(getProvider('/me/vouchers'));
    final ar = context.s.isArabic;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('shop.orders'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(context.tr('shop.voucher'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: TextField(controller: _code, textCapitalization: TextCapitalization.characters, textDirection: TextDirection.ltr, decoration: const InputDecoration(hintText: 'XXXX-XXXX-XXXX'))),
          const SizedBox(width: 8),
          FilledButton(onPressed: _busy || _code.text.trim().isEmpty ? null : _redeem, child: Text(context.tr('shop.use'))),
        ]),
        AsyncView(
          value: vouchers,
          onRetry: () => ref.invalidate(getProvider('/me/vouchers')),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            return Column(children: [
              for (final v in rows)
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.confirmation_number_outlined, color: AppColors.gold500),
                  title: Text(ar ? v.str('program_ar') : v.str('program_en')),
                  subtitle: Text(v.str('code').isEmpty ? v.str('status') : '${v.str('code')} · ${v.str('status')}'),
                ),
            ]);
          },
        ),
        const SizedBox(height: 16),
        Text(context.tr('shop.orders'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        AsyncView(
          value: orders,
          onRetry: () => ref.invalidate(getProvider('/me/orders')),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            if (rows.isEmpty) return EmptyView(text: context.tr('shop.noOrders'));
            return Column(children: [
              for (final o in rows)
                Card(
                  child: ListTile(
                    title: Text(o.str('number'), textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${context.tr('shop.status.${o.str('status')}')}${o.str('invoice_no').isEmpty ? '' : ' · ${o.str('invoice_no')}'}'),
                    trailing: Text(_money(context, o.number('total')), style: const TextStyle(fontWeight: FontWeight.w800)),
                  ),
                ),
            ]);
          },
        ),
      ]),
    );
  }
}
