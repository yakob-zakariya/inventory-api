"""Manual-test scenarios for the API: one definition, two outputs.

    python3 docs/postman/scenarios.py export docs/postman/inventory-api.postman_collection.json
        Writes the Postman collection (each request has its body and pass/fail checks).
    python3 docs/postman/scenarios.py run http://127.0.0.1:8000/api/v1
        Runs the same steps and checks against a server started on fresh demo data
        (php artisan migrate:fresh --seed && php artisan serve). Exit code 0 = all passed.

Add a folder to FOLDERS for each new phase, then export again.
"""
import json, re, sys, urllib.request, urllib.error

OWNER, STAFF = "ownerToken", "staffToken"

# step: (name, method, path, token, body, status, checks, captures, note)
# checks: {"json.path": value} or {"json.path": MISSING}; captures: {"var": "json.path"}
MISSING = object()
def step(name, method, path, token=None, body=None, status=200, checks=None, captures=None, note=""):
    return dict(name=name, method=method, path=path, token=token, body=body, status=status,
                checks=checks or {}, captures=captures or {}, note=note)

FOLDERS = [
 ("Setup — log in", "Run `php artisan migrate:fresh --seed` first. These save the tokens for every later request.", [
  step("Log in as owner", "POST", "/auth/login", body={"email": "owner@example.com", "password": "password"},
       checks={"data.user.roles": ["owner"]}, captures={OWNER: "data.token"}),
  step("Log in as staff", "POST", "/auth/login", body={"email": "staff@example.com", "password": "password"},
       checks={"data.user.roles": ["staff"]}, captures={STAFF: "data.token"}),
 ]),
 ("Phase 1 — Auth, users and errors", "Login, the current user, logout, user management and the error format.", [
  step("Who am I (owner)", "GET", "/auth/me", OWNER,
       checks={"data.email": "owner@example.com", "data.roles": ["owner"], "data.permissions.0": "accounts.manage"}),
  step("Who am I (staff)", "GET", "/auth/me", STAFF,
       checks={"data.permissions": ["customers.manage", "payments.record", "sales.create"]}),
  step("Wrong password", "POST", "/auth/login", status=422, body={"email": "owner@example.com", "password": "wrong-password"},
       checks={"errors.email.0": "These credentials do not match our records."}),
  step("Login needs email and password", "POST", "/auth/login", status=422, body={},
       checks={"errors.email.0": "The email field is required.", "errors.password.0": "The password field is required."}),
  step("Log in on a second device", "POST", "/auth/login", body={"email": "owner@example.com", "password": "password", "device_name": "Postman logout test"},
       captures={"secondDeviceToken": "data.token"}, note="device_name is optional; it names the token."),
  step("Log out that device", "POST", "/auth/logout", "secondDeviceToken", status=204, note="Only this token is revoked; the owner token still works."),
  step("The logged-out token no longer works", "GET", "/auth/me", "secondDeviceToken", status=401, checks={"message": "Unauthenticated."}),
  step("Not logged in", "GET", "/auth/me", status=401, checks={"message": "Unauthenticated."}),
  step("Owner lists users", "GET", "/users", OWNER, checks={"data.0.name": "Demo Owner", "data.0.roles": ["owner"]}),
  step("Owner creates a staff user", "POST", "/users", OWNER, status=201,
       body={"name": "Cashier Two", "email": "cashier2@example.com", "password": "secret-password",
             "password_confirmation": "secret-password", "role": "staff"},
       checks={"data.email": "cashier2@example.com", "data.roles": ["staff"]}),
  step("Email already taken", "POST", "/users", OWNER, status=422,
       body={"name": "Copy", "email": "cashier2@example.com", "password": "secret-password",
             "password_confirmation": "secret-password", "role": "staff"},
       checks={"errors.email.0": "The email has already been taken."}),
  step("The new user can log in", "POST", "/auth/login", body={"email": "cashier2@example.com", "password": "secret-password"},
       checks={"data.user.roles": ["staff"]}),
  step("Staff cannot manage users", "GET", "/users", STAFF, status=403, checks={"message": "This action is unauthorized."}),
  step("Unknown record", "GET", "/products/9999", OWNER, status=404, checks={"message": "Resource not found."}),
 ]),
 ("Phase 2 — Master data", "Categories, products, suppliers, customers and accounts. Creates its own records so later folders are unaffected.", [
  step("Anyone logged in can list categories", "GET", "/categories", STAFF, checks={"data.0.name": "Building materials"}),
  step("Create a category", "POST", "/categories", OWNER, status=201, body={"name": "Plumbing", "description": "Pipes and fittings"},
       checks={"data.name": "Plumbing"}, captures={"categoryId": "data.id"}),
  step("Category names are unique", "POST", "/categories", OWNER, status=422, body={"name": "Plumbing"},
       checks={"errors.name.0": "The name has already been taken."}),
  step("Staff cannot create categories", "POST", "/categories", STAFF, status=403, body={"name": "Tools"}),
  step("Update a category", "PATCH", "/categories/{{categoryId}}", OWNER, body={"description": "Pipes, fittings and taps"},
       checks={"data.description": "Pipes, fittings and taps", "data.name": "Plumbing"}),
  step("Create a product", "POST", "/products", OWNER, status=201,
       body={"category_id": "{{categoryId}}", "sku": "PIPE-1", "name": "PVC pipe 1 inch", "unit": "piece",
             "cost_price": 50, "selling_price": "65.5", "reorder_level": 5},
       checks={"data.sku": "PIPE-1", "data.cost_price": "50.00", "data.selling_price": "65.50", "data.stock": 0, "data.category.name": "Plumbing"},
       captures={"productId": "data.id"}, note="Money always comes back as a string with two decimals."),
  step("SKUs are unique", "POST", "/products", OWNER, status=422,
       body={"category_id": "{{categoryId}}", "sku": "PIPE-1", "name": "Copy", "unit": "piece", "cost_price": 1, "selling_price": 1},
       checks={"errors.sku.0": "The sku has already been taken."}),
  step("Prices have at most two decimals", "POST", "/products", OWNER, status=422,
       body={"category_id": "{{categoryId}}", "sku": "PIPE-2", "name": "Pipe", "unit": "piece", "cost_price": "1.005", "selling_price": 2},
       checks={"errors.cost_price.0": "The cost price field must have 0-2 decimal places."}),
  step("Search products", "GET", "/products?search=PIPE", STAFF, checks={"data.0.sku": "PIPE-1"}),
  step("A category with products cannot be deleted", "DELETE", "/categories/{{categoryId}}", OWNER, status=409,
       checks={"message": "This category still has products and cannot be deleted."}),
  step("Create a supplier", "POST", "/suppliers", OWNER, status=201,
       body={"name": "Addis Pipes", "contact_person": "Abebe", "phone": "+251911223344", "email": "sales@addispipes.example"},
       checks={"data.name": "Addis Pipes"}),
  step("Staff cannot create suppliers", "POST", "/suppliers", STAFF, status=403, body={"name": "Other"}),
  step("Staff registers a customer", "POST", "/customers", STAFF, status=201, body={"name": "Almaz", "phone": "+251922000111"},
       checks={"data.name": "Almaz"}, captures={"customerId": "data.id"}, note="Staff can register customers for credit sales."),
  step("Customer phones are unique", "POST", "/customers", STAFF, status=422, body={"name": "Other", "phone": "+251922000111"},
       checks={"errors.phone.0": "The phone has already been taken."}),
  step("Delete the customer (soft delete)", "DELETE", "/customers/{{customerId}}", STAFF, status=204),
  step("A deleted customer is gone", "GET", "/customers/{{customerId}}", STAFF, status=404),
  step("Create an account", "POST", "/accounts", OWNER, status=201, body={"name": "Telebirr", "type": "mobile_money"},
       checks={"data.type": "mobile_money", "data.opening_balance": "0.00", "data.balance": "0.00"}, captures={"accountId": "data.id"}),
  step("Opening balance cannot be edited", "PATCH", "/accounts/{{accountId}}", OWNER, status=422, body={"opening_balance": "100.00"},
       checks={"errors.opening_balance.0": "The opening balance cannot be changed. Record an adjustment instead."}),
  step("Deactivate the account", "PATCH", "/accounts/{{accountId}}", OWNER, body={"is_active": False}, checks={"data.is_active": False}),
  step("Accounts cannot be deleted", "DELETE", "/accounts/{{accountId}}", OWNER, status=405),
  step("Staff cannot create accounts", "POST", "/accounts", STAFF, status=403, body={"name": "Other", "type": "cash"}),
 ]),
 ("Phase 3 — Stock", "Stock is the sum of stock movements. Uses the product from phase 2 and brings it back to zero.", [
  step("Add opening stock", "POST", "/stock-adjustments", OWNER, status=201,
       body={"product_id": "{{productId}}", "quantity": 20, "note": "Opening stock"},
       checks={"data.type": "adjustment", "data.quantity": 20, "data.product.sku": "PIPE-1"}),
  step("Product stock is calculated", "GET", "/products/{{productId}}", STAFF, checks={"data.stock": 20}),
  step("Stock cannot go negative", "POST", "/stock-adjustments", OWNER, status=422,
       body={"product_id": "{{productId}}", "quantity": -25, "note": "Recount"},
       checks={"message": "Not enough stock for PIPE-1: 20 on hand, 25 requested."}),
  step("Adjustments need a reason", "POST", "/stock-adjustments", OWNER, status=422,
       body={"product_id": "{{productId}}", "quantity": 1},
       checks={"errors.note.0": "A reason is required for every stock adjustment."}),
  step("Staff cannot adjust stock", "POST", "/stock-adjustments", STAFF, status=403,
       body={"product_id": "{{productId}}", "quantity": 1, "note": "x"}),
  step("Stock history of the product", "GET", "/stock-movements?product_id={{productId}}", STAFF,
       checks={"data.0.quantity": 20, "data.0.note": "Opening stock"}),
  step("A product with stock cannot be deleted", "DELETE", "/products/{{productId}}", OWNER, status=409,
       checks={"message": "This product still has 20 in stock. Adjust stock to zero before deleting it."}),
  step("Take the stock back out", "POST", "/stock-adjustments", OWNER, status=201,
       body={"product_id": "{{productId}}", "quantity": -20, "note": "Returned to supplier"},
       checks={"data.quantity": -20}),
  step("Now the product can be deleted", "DELETE", "/products/{{productId}}", OWNER, status=204),
  step("Its history stays", "GET", "/stock-movements?product_id={{productId}}", OWNER, checks={"data.0.quantity": -20, "data.1.quantity": 20}),
  step("Its category still cannot be deleted", "DELETE", "/categories/{{categoryId}}", OWNER, status=409,
       note="Deleted products still belong to the category, so their history stays intact."),
 ]),
 ("Phase 4 — Purchases and sales", "Seed IDs: products 1 cement, 2 nails, 3 paint; supplier 1; customer 1; accounts 1 cash, 2 bank.", [
  step("List purchases", "GET", "/purchases", OWNER, checks={"data.0.number": "PUR-000001", "data.0.payment_status": "partial"}),
  step("Show purchase PUR-000001", "GET", "/purchases/1", OWNER,
       checks={"data.total_amount": "12800.00", "data.paid_amount": "5000.00", "data.balance_due": "7800.00"}),
  step("Create purchase on part credit", "POST", "/purchases", OWNER, status=201,
       body={"supplier_id": 1, "invoice_number": "INV-2002", "items": [{"product_id": 3, "quantity": 10, "unit_cost": "950.00"}],
             "payments": [{"account_id": 2, "amount": "4000.00"}]},
       checks={"data.number": "PUR-000002", "data.total_amount": "9500.00", "data.paid_amount": "4000.00", "data.payment_status": "partial"},
       captures={"purchaseId": "data.id"}, note="Total is computed by the server. The product's cost price becomes 950."),
  step("Product cost follows the last purchase", "GET", "/products/3", OWNER, checks={"data.cost_price": "950.00", "data.stock": 59}),
  step("Staff cannot see purchases", "GET", "/purchases", STAFF, status=403, checks={"message": "This action is unauthorized."}),
  step("Staff sells to a walk-in, paid in full", "POST", "/sales", STAFF, status=201,
       body={"items": [{"product_id": 1, "quantity": 3}], "payments": [{"account_id": 1, "amount": "2160.00"}]},
       checks={"data.number": "SAL-000003", "data.payment_status": "paid", "data.items.0.unit_price": "720.00", "data.items.0.unit_cost": MISSING},
       note="No unit_price sent: the selling price is used. Staff never see unit_cost."),
  step("Credit sale with a negotiated price", "POST", "/sales", OWNER, status=201,
       body={"customer_id": 1, "items": [{"product_id": 2, "quantity": 10, "unit_price": "140.00"}], "payments": [{"account_id": 1, "amount": "400.00"}]},
       checks={"data.number": "SAL-000004", "data.total_amount": "1400.00", "data.balance_due": "1000.00",
               "data.payment_status": "partial", "data.items.0.unit_cost": "120.00"},
       captures={"creditSaleId": "data.id"}),
  step("Walk-in sale must be paid in full", "POST", "/sales", OWNER, status=422,
       body={"items": [{"product_id": 1, "quantity": 1}], "payments": [{"account_id": 1, "amount": "100.00"}]},
       checks={"message": "A customer is required for a sale that is not fully paid."}),
  step("Cannot sell more than in stock", "POST", "/sales", OWNER, status=422,
       body={"customer_id": 1, "items": [{"product_id": 3, "quantity": 1000}]},
       checks={"message": "Not enough stock for PNT-W4: 59 on hand, 1000 requested."}),
  step("Same product twice is rejected", "POST", "/sales", OWNER, status=422,
       body={"customer_id": 1, "items": [{"product_id": 1, "quantity": 1}, {"product_id": 1, "quantity": 1}]},
       checks={"errors.items\\.1\\.product_id.0": "Each product may appear only once on a document."}),
  step("Sales of customer 1", "GET", "/sales?customer_id=1", OWNER, checks={"data.0.number": "SAL-000004", "data.1.number": "SAL-000002"}),
 ]),
 ("Phase 5 — Money", "Balances are calculated from the ledger, never stored.", [
  step("Owner sees account balances", "GET", "/accounts", OWNER,
       checks={"data.0.name": "Bank", "data.0.balance": "11000.00", "data.1.balance": "10300.00"}),
  step("Staff sees accounts without balances", "GET", "/accounts", STAFF, checks={"data.0.balance": MISSING}),
  step("Staff collects the rest of the credit sale", "POST", "/payments", STAFF, status=201,
       body={"document_type": "sale", "document_id": "{{creditSaleId}}", "account_id": 1, "amount": "1000.00", "receipt_reference": "RCPT-1"},
       checks={"data.payment_status": "paid", "data.balance_due": "0.00"}),
  step("Staff cannot pay a supplier", "POST", "/payments", STAFF, status=403,
       body={"document_type": "purchase", "document_id": "{{purchaseId}}", "account_id": 2, "amount": "100.00"},
       checks={"message": "This action is unauthorized."}),
  step("Owner pays the supplier", "POST", "/payments", OWNER, status=201,
       body={"document_type": "purchase", "document_id": "{{purchaseId}}", "account_id": 2, "amount": "5500.00"},
       checks={"data.payment_status": "paid"}),
  step("Payment above the balance due is refused", "POST", "/payments", OWNER, status=422,
       body={"document_type": "purchase", "document_id": 1, "account_id": 2, "amount": "8000.00"},
       checks={"message": "Payment (8000.00) exceeds the balance due on PUR-000001 (7800.00)."}),
  step("Record an expense", "POST", "/expenses", OWNER, status=201,
       body={"account_id": 1, "amount": "1500.00", "note": "October rent", "receipt_reference": "RENT-10"},
       checks={"data.type": "expense", "data.amount": "-1500.00"}),
  step("Expense needs a reason", "POST", "/expenses", OWNER, status=422, body={"account_id": 1, "amount": "10.00"},
       checks={"errors.note.0": "A reason is required for every expense."}),
  step("Transfer cash to bank", "POST", "/transfers", OWNER, status=201,
       body={"from_account_id": 1, "to_account_id": 2, "amount": "3000.00", "note": "Bank deposit"},
       checks={"data.0.type": "transfer_out", "data.0.amount": "-3000.00", "data.1.type": "transfer_in", "data.1.amount": "3000.00"}),
  step("Owner withdrawal", "POST", "/owner-entries", OWNER, status=201,
       body={"type": "withdrawal", "account_id": 2, "amount": "1000.00", "note": "Family expenses"},
       checks={"data.type": "owner_withdrawal", "data.amount": "-1000.00"}),
  step("Cash balance before counting", "GET", "/accounts/1", OWNER, checks={"data.balance": "6800.00"}),
  step("Reconcile the till to the counted amount", "POST", "/account-adjustments", OWNER, status=201,
       body={"account_id": 1, "counted_balance": "6750.00", "note": "Till count, evening"},
       checks={"data.type": "adjustment", "data.amount": "-50.00"}, note="The server records the difference: 6750 - 6800."),
  step("Cash ledger, newest first", "GET", "/account-transactions?account_id=1", OWNER,
       checks={"data.0.type": "adjustment", "data.1.type": "transfer_out", "data.2.type": "expense"}),
  step("Business summary", "GET", "/reports/summary", OWNER,
       checks={"data.accounts.total": "14250.00", "data.inventory.at_cost": "101850.00", "data.receivables": "525.00",
               "data.payables": "7800.00", "data.net_worth": "108825.00"},
       note="Net worth = money + inventory at cost + receivables - payables."),
  step("Staff cannot see the summary", "GET", "/reports/summary", STAFF, status=403),
 ]),
 ("Phase 6 — Voiding and reversals", "Mistakes are cancelled, never edited: a void reverses a document's stock, a reversal cancels a ledger entry.", [
  step("A credit sale that turns out wrong", "POST", "/sales", OWNER, status=201,
       body={"customer_id": 1, "items": [{"product_id": 3, "quantity": 2}], "payments": [{"account_id": 1, "amount": "500.00"}]},
       checks={"data.number": "SAL-000005", "data.paid_amount": "500.00"},
       captures={"wrongSaleId": "data.id", "wrongPaymentId": "data.payments.0.id"}),
  step("Paint stock went down", "GET", "/products/3", OWNER, checks={"data.stock": 57}),
  step("A sale with payments cannot be voided", "POST", "/sales/{{wrongSaleId}}/void", OWNER, status=422,
       body={"reason": "Wrong customer"},
       checks={"message": "SAL-000005 has payments of 500.00. Reverse them before voiding it."}),
  step("Staff cannot reverse a payment", "POST", "/account-transactions/{{wrongPaymentId}}/reverse", STAFF, status=403,
       body={"reason": "Wrong customer"}),
  step("Reverse the payment", "POST", "/account-transactions/{{wrongPaymentId}}/reverse", OWNER, status=201,
       body={"reason": "Wrong customer"},
       checks={"data.0.type": "reversal", "data.0.amount": "-500.00", "data.0.note": "Wrong customer", "data.0.reference_type": "sale"},
       captures={"paymentReversalId": "data.0.id"},
       note="The reversal keeps the sale as its reference, so the sale's paid amount drops back."),
  step("The sale is unpaid again", "GET", "/sales/{{wrongSaleId}}", OWNER,
       checks={"data.paid_amount": "0.00", "data.payment_status": "unpaid"}),
  step("A payment is reversed only once", "POST", "/account-transactions/{{wrongPaymentId}}/reverse", OWNER, status=409,
       body={"reason": "Again"}),
  step("A reversal cannot be reversed", "POST", "/account-transactions/{{paymentReversalId}}/reverse", OWNER, status=422,
       body={"reason": "Undo"},
       checks={"message": "A reversal cannot be reversed. Record the correct entry again instead."}),
  step("Staff cannot void a sale", "POST", "/sales/{{wrongSaleId}}/void", STAFF, status=403, body={"reason": "Wrong customer"}),
  step("A void needs a reason", "POST", "/sales/{{wrongSaleId}}/void", OWNER, status=422, body={},
       checks={"errors.reason.0": "A reason is required to void a document."}),
  step("Void the sale", "POST", "/sales/{{wrongSaleId}}/void", OWNER,
       body={"reason": "Wrong customer"},
       checks={"data.status": "voided", "data.void_reason": "Wrong customer"}),
  step("Its stock is back", "GET", "/products/3", OWNER, checks={"data.stock": 59}),
  step("A document is voided only once", "POST", "/sales/{{wrongSaleId}}/void", OWNER, status=409,
       body={"reason": "Again"}, checks={"message": "SAL-000005 is already voided."}),
  step("A new product at cost 80", "POST", "/products", OWNER, status=201,
       body={"category_id": 1, "sku": "TAPE-1", "name": "Measuring tape", "unit": "piece", "cost_price": "80.00", "selling_price": "150.00"},
       captures={"tapeId": "data.id"}),
  step("Buy 5 tapes, cost typed wrong as 1000", "POST", "/purchases", OWNER, status=201,
       body={"supplier_id": 1, "items": [{"product_id": "{{tapeId}}", "quantity": 5, "unit_cost": "1000.00"}]},
       checks={"data.number": "PUR-000003", "data.total_amount": "5000.00"},
       captures={"tapePurchaseId": "data.id"}),
  step("Sell 4 tapes", "POST", "/sales", OWNER, status=201,
       body={"customer_id": 1, "items": [{"product_id": "{{tapeId}}", "quantity": 4}]},
       checks={"data.number": "SAL-000006"}, captures={"tapeSaleId": "data.id"}),
  step("The purchase cannot be voided: its goods are sold", "POST", "/purchases/{{tapePurchaseId}}/void", OWNER, status=422,
       body={"reason": "Wrong unit cost"},
       checks={"message": "Not enough stock for TAPE-1: 1 on hand, 5 requested."}),
  step("Void the tape sale first", "POST", "/sales/{{tapeSaleId}}/void", OWNER, body={"reason": "Sold before the cost was fixed"},
       checks={"data.status": "voided"}),
  step("Now void the purchase", "POST", "/purchases/{{tapePurchaseId}}/void", OWNER, body={"reason": "Wrong unit cost"},
       checks={"data.status": "voided", "data.void_reason": "Wrong unit cost"}),
  step("The tape's cost is back to 80 and its stock to 0", "GET", "/products/{{tapeId}}", OWNER,
       checks={"data.cost_price": "80.00", "data.stock": 0},
       note="The purchase had set the cost to 1000; voiding it restores the cost from before."),
  step("Transfer 200 cash to bank", "POST", "/transfers", OWNER, status=201,
       body={"from_account_id": 1, "to_account_id": 2, "amount": "200.00"},
       captures={"transferInId": "data.1.id"}),
  step("Reverse the transfer from its bank side", "POST", "/account-transactions/{{transferInId}}/reverse", OWNER, status=201,
       body={"reason": "Deposit never happened"},
       checks={"data.0.amount": "200.00", "data.0.account_id": 1, "data.1.amount": "-200.00", "data.1.account_id": 2},
       note="Either side reverses both: two reversal rows sharing a new transfer group."),
  step("Cash balance is unchanged by the whole folder", "GET", "/accounts/1", OWNER, checks={"data.balance": "6750.00"}),
  step("Cash ledger shows the transfer reversal", "GET", "/account-transactions?account_id=1", OWNER,
       checks={"data.0.type": "reversal", "data.0.amount": "200.00", "data.1.type": "transfer_out"},
       note="data.1.reversed_by_transaction_id points at data.0."),
  step("Voided documents are left out of the summary", "GET", "/reports/summary", OWNER,
       checks={"data.payables": "7800.00", "data.receivables": "525.00", "data.net_worth": "108825.00"}),
 ]),
 ("Phase 7 — Reports", "Reports for the current month (the demo data is dated today). The list reports are filters on the normal lists.", [
  step("Profit this month", "GET", "/reports/profit", OWNER,
       checks={"data.sales": "6825.00", "data.cost_of_sales": "5900.00", "data.gross_profit": "925.00",
               "data.expenses": "1500.00", "data.net_profit": "-575.00"},
       note="Voided sales are left out. The whole month's rent counts against a few days of sales, hence the loss."),
  step("Profit by month", "GET", "/reports/profit?group_by=month", OWNER, checks={"data.series.0.net_profit": "-575.00"},
       note="group_by=day|month adds a series with every period present, for charts."),
  step("Unknown grouping", "GET", "/reports/profit?group_by=week", OWNER, status=422, checks={"errors.group_by.0": "The selected group by is invalid."}),
  step("Cash flow, all accounts", "GET", "/reports/cash-flow", OWNER,
       checks={"data.opening_balance": "25000.00", "data.money_in": "6300.00", "data.money_out": "17050.00",
               "data.closing_balance": "14250.00", "data.by_type.0.type": "sale_payment", "data.by_type.0.in": "6300.00"},
       note="Transfers are left out across all accounts. Reversed entries net into the type they cancel. Closing = the summary's account total."),
  step("Cash flow, cash drawer", "GET", "/reports/cash-flow?account_id=1", OWNER,
       checks={"data.account.name": "Cash drawer", "data.closing_balance": "6750.00", "data.by_type.2.type": "transfer_out"},
       note="For one account, transfers are real money in or out."),
  step("Staff cannot see reports", "GET", "/reports/cash-flow", STAFF, status=403),
  step("Customers who owe", "GET", "/customers?has_balance=1", OWNER, checks={"data.0.name": "Demo Credit Customer", "data.0.receivable": "525.00"}),
  step("Suppliers we owe", "GET", "/suppliers?has_balance=1", OWNER, checks={"data.0.payable": "7800.00"}),
  step("Out of stock products", "GET", "/products?stock=out", STAFF, checks={"data.0.sku": "TAPE-1", "data.0.stock": 0}),
 ]),
 ("Roles and users", "Custom roles, immediate permission changes, deactivation and passwords.", [
  step("Permissions for the role editor", "GET", "/permissions", OWNER,
       checks={"data.5.name": "sales.void", "data.5.label": "Void sales", "data.5.group": "Sales"}),
  step("Staff cannot manage roles", "GET", "/roles", STAFF, status=403),
  step("Create a Cashier role", "POST", "/roles", OWNER, status=201,
       body={"name": "Cashier", "permissions": ["sales.create", "payments.record"]},
       checks={"data.permissions": ["payments.record", "sales.create"], "data.is_locked": False},
       captures={"cashierRoleId": "data.id"}),
  step("Unknown permissions are rejected", "POST", "/roles", OWNER, status=422,
       body={"name": "Pilot", "permissions": ["planes.fly"]}, checks={"errors.permissions\\.0.0": "The selected permissions.0 is invalid."}),
  step("Give a new user the Cashier role", "POST", "/users", OWNER, status=201,
       body={"name": "Tigist Cashier", "email": "tigist@example.com", "password": "secret-password",
             "password_confirmation": "secret-password", "role": "Cashier"},
       checks={"data.role": "Cashier", "data.is_active": True}, captures={"cashierId": "data.id"}),
  step("Cashier logs in", "POST", "/auth/login", body={"email": "tigist@example.com", "password": "secret-password"},
       checks={"data.user.roles": ["Cashier"]}, captures={"cashierToken": "data.token"}),
  step("Cashier cannot see reports yet", "GET", "/reports/summary", "cashierToken", status=403),
  step("Owner adds reports.view to Cashier", "PATCH", "/roles/{{cashierRoleId}}", OWNER,
       body={"permissions": ["sales.create", "payments.record", "reports.view"]},
       checks={"data.permissions": ["payments.record", "reports.view", "sales.create"]}),
  step("Cashier sees reports at once, same token", "GET", "/reports/summary", "cashierToken",
       note="Permissions are read on every request, so nobody has to log in again."),
  step("A role in use cannot be deleted", "DELETE", "/roles/{{cashierRoleId}}", OWNER, status=409,
       checks={"message": "1 user still has the role \"Cashier\". Give them another role first."}),
  step("The owner role is locked", "PATCH", "/roles/1", OWNER, status=409, body={"permissions": []},
       checks={"message": "The owner role always has every permission and cannot be changed or deleted."}),
  step("The last owner cannot demote themselves", "PATCH", "/users/1", OWNER, status=409, body={"role": "staff"},
       checks={"message": "This change would leave no active user who can manage users."}),
  step("Cashier changes their own password", "POST", "/auth/password", "cashierToken", status=204,
       body={"current_password": "secret-password", "password": "cashier-pass-2", "password_confirmation": "cashier-pass-2"}),
  step("Deactivate the cashier", "POST", "/users/{{cashierId}}/deactivate", OWNER, checks={"data.is_active": False}),
  step("Their token stops working at once", "GET", "/auth/me", "cashierToken", status=401, checks={"message": "Unauthenticated."},
       note="Deactivating deleted the token; a surviving token would get \"This account is deactivated.\""),
  step("They cannot log in", "POST", "/auth/login", status=422, body={"email": "tigist@example.com", "password": "cashier-pass-2"},
       checks={"errors.email.0": "This account is deactivated. Ask the shop owner to reactivate it."}),
  step("Reactivate the cashier", "POST", "/users/{{cashierId}}/activate", OWNER, checks={"data.is_active": True}),
  step("Owner sets a new password for them", "POST", "/users/{{cashierId}}/password", OWNER, status=204,
       body={"password": "reset-by-owner", "password_confirmation": "reset-by-owner"}),
  step("They log in with the new password", "POST", "/auth/login", body={"email": "tigist@example.com", "password": "reset-by-owner"},
       checks={"data.user.roles": ["Cashier"]}),
 ]),
 ("Proformas", "Quote, edit, convert to a sale, cancel. A proforma never touches stock or money until it is converted.", [
  step("Owner fills in the shop details", "PATCH", "/shop", OWNER,
       body={"name": "Demo Building Supplies", "address": "Bole, Addis Ababa", "phone": "0911 000000", "tin": "0012345678"},
       checks={"data.phone": "0911 000000"}, note="SHOP_* in .env are only the starting values."),
  step("Staff cannot change them", "PATCH", "/shop", STAFF, status=403, body={"phone": "0900 000000"}),
  step("The currency is fixed", "PATCH", "/shop", OWNER, status=422, body={"currency": "USD"},
       checks={"errors.currency.0": "The currency is set when the system is installed and cannot be changed here."}),
  step("Shop details for the printout", "GET", "/shop", STAFF, checks={"data.phone": "0911 000000", "data.currency": "ETB"}),
  step("Preview a quote", "POST", "/proformas/preview", STAFF,
       body={"items": [{"product_id": 1, "quantity": 2, "unit_price": "700.00"}, {"product_id": 3, "quantity": 1, "unit_price": "1000.00"}]},
       checks={"data.items.0.line_total": "1400.00", "data.total_amount": "2400.00"}),
  step("Quote for someone not yet a customer", "POST", "/proformas", STAFF, status=201,
       body={"customer_name": "Hanna Construction", "customer_phone": "0911 223344",
             "items": [{"product_id": 1, "quantity": 2, "unit_price": "700.00"}, {"product_id": 3, "quantity": 1, "unit_price": "1000.00"}]},
       checks={"data.number": "PRF-000001", "data.status": "open", "data.total_amount": "2400.00", "data.customer": None},
       captures={"proformaId": "data.id"}, note="valid_until defaults to 7 days after the proforma date."),
  step("The customer wants 2 tins of paint", "PATCH", "/proformas/{{proformaId}}", STAFF,
       body={"items": [{"product_id": 1, "quantity": 2, "unit_price": "700.00"}, {"product_id": 3, "quantity": 2, "unit_price": "1000.00"}]},
       checks={"data.total_amount": "3400.00"}, note="Items replace the whole list; the total is recalculated."),
  step("Converting a typed-in customer on credit is refused", "POST", "/proformas/{{proformaId}}/convert", STAFF, status=422,
       body={"items": [{"product_id": 1, "quantity": 1}, {"product_id": 3, "quantity": 2}]},
       checks={"message": "A customer is required for a sale that is not fully paid."}),
  step("They take 1 bag less and pay in full", "POST", "/proformas/{{proformaId}}/convert", STAFF, status=201,
       body={"items": [{"product_id": 1, "quantity": 1}, {"product_id": 3, "quantity": 2}],
             "payments": [{"account_id": 1, "amount": "2700.00"}]},
       checks={"data.total_amount": "2700.00", "data.payment_status": "paid", "data.items.0.unit_price": "700.00",
               "data.proforma.number": "PRF-000001", "data.note": "From PRF-000001 (Hanna Construction)"},
       note="Quoted prices are kept; stock and payments follow the normal sale rules."),
  step("It cannot be converted twice", "POST", "/proformas/{{proformaId}}/convert", STAFF, status=409,
       checks={"message": "PRF-000001 was already converted to a sale."}),
  step("The proforma links to its sale", "GET", "/proformas/{{proformaId}}", STAFF,
       checks={"data.status": "converted", "data.sale.number": "SAL-000007"}),
  step("A quote that was lost", "POST", "/proformas", STAFF, status=201,
       body={"customer_id": 1, "items": [{"product_id": 2, "quantity": 10}]}, captures={"lostProformaId": "data.id"}),
  step("Cancel it with a reason", "POST", "/proformas/{{lostProformaId}}/cancel", STAFF,
       body={"reason": "Customer bought elsewhere"}, checks={"data.status": "cancelled"}),
  step("A cancelled proforma cannot change", "PATCH", "/proformas/{{lostProformaId}}", STAFF, status=409,
       body={"note": "x"}, checks={"message": "PRF-000002 is cancelled."}),
  step("Converted proformas", "GET", "/proformas?status=converted", OWNER, checks={"data.0.number": "PRF-000001"}),
 ]),
]

def split_path(path):
    # "a.b\.c.0" -> ["a", "b.c", "0"]
    return [p.replace("\\.", ".") for p in re.split(r"(?<!\\)\.", path)]

def js_access(path):
    return "j" + "".join(f"[{p}]" if p.isdigit() else f"[{json.dumps(p)}]" for p in split_path(path))

def captured_variables():
    return list(dict.fromkeys(var for _, _, steps in FOLDERS for s in steps for var in s["captures"] if var not in (OWNER, STAFF)))

def postman():
    def item(s):
        tests = [f'pm.test("status {s["status"]}", () => pm.response.to.have.status({s["status"]}));']
        if s["checks"] or s["captures"]:
            tests.append("const j = pm.response.json();")
        for path, value in s["checks"].items():
            parts = split_path(path)
            if value is MISSING:
                parent = js_access(".".join(p.replace(".", "\\.") for p in parts[:-1]))
                tests.append(f'pm.test("{path} is not shown", () => pm.expect({parent}).to.not.have.property({json.dumps(parts[-1])}));')
            else:
                tests.append(f'pm.test({json.dumps(f"{path} = {json.dumps(value)}")}, () => pm.expect({js_access(path)}).to.eql({json.dumps(value)}));')
        for var, path in s["captures"].items():
            tests.append(f'pm.collectionVariables.set("{var}", {js_access(path)});')
        req = {"method": s["method"], "header": [{"key": "Accept", "value": "application/json"}],
               "url": {"raw": "{{baseUrl}}" + s["path"], "host": ["{{baseUrl}}"],
                       "path": [p for p in s["path"].split("?")[0].strip("/").split("/")],
                       **({"query": [{"key": k, "value": v} for k, v in (q.split("=") for q in s["path"].split("?")[1].split("&"))]} if "?" in s["path"] else {})},
               "description": s["note"]}
        if s["token"]:
            req["auth"] = {"type": "bearer", "bearer": [{"key": "token", "value": "{{" + s["token"] + "}}", "type": "string"}]}
        else:
            req["auth"] = {"type": "noauth"}
        if s["body"] is not None:
            raw = json.dumps(s["body"], indent=2)
            raw = re.sub(r'"(\{\{\w+\}\})"', r"\1", raw)  # captured ids are numbers
            req["header"].append({"key": "Content-Type", "value": "application/json"})
            req["body"] = {"mode": "raw", "raw": raw, "options": {"raw": {"language": "json"}}}
        return {"name": s["name"], "request": req, "event": [{"listen": "test", "script": {"type": "text/javascript", "exec": tests}}]}
    return {
        "info": {"name": "Inventory API — scenarios", "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
                 "description": "Run `php artisan migrate:fresh --seed`, start `php artisan serve`, then Run collection from the top. "
                                "Steps depend on each other. For every endpoint and field, see the generated docs at /docs/api."},
        "variable": [{"key": "baseUrl", "value": "http://localhost:8000/api/v1"}]
                    + [{"key": key, "value": ""} for key in [OWNER, STAFF, *captured_variables()]],
        "item": [{"name": n, "description": d, "item": [item(s) for s in steps]} for n, d, steps in FOLDERS],
    }

def run(base):
    vars_, failures, count = {}, 0, 0
    def sub(x):
        if isinstance(x, str):
            m = re.fullmatch(r"\{\{(\w+)\}\}", x)
            return vars_[m.group(1)] if m else x
        if isinstance(x, list): return [sub(v) for v in x]
        if isinstance(x, dict): return {k: sub(v) for k, v in x.items()}
        return x
    def get(j, path):
        for p in split_path(path):
            j = j[int(p)] if isinstance(j, list) else j[p]
        return j
    for folder, _, steps in FOLDERS:
        print(f"\n{folder}")
        for s in steps:
            body = json.dumps(sub(s["body"])).encode() if s["body"] is not None else None
            headers = {"Accept": "application/json", "Content-Type": "application/json"}
            if s["token"]: headers["Authorization"] = "Bearer " + str(vars_[s["token"]])
            path = re.sub(r"\{\{(\w+)\}\}", lambda m: str(vars_[m.group(1)]), s["path"])
            req = urllib.request.Request(base + path, data=body, method=s["method"], headers=headers)
            try:
                with urllib.request.urlopen(req) as r: code, j = r.status, json.loads(r.read() or b"null")
            except urllib.error.HTTPError as e: code, j = e.code, json.loads(e.read() or b"null")
            problems = [] if code == s["status"] else [f"status {code} != {s['status']}"]
            for path, value in s["checks"].items():
                try:
                    actual = get(j, path)
                    if value is MISSING: problems.append(f"{path} should be hidden")
                    elif actual != value: problems.append(f"{path}: {actual!r} != {value!r}")
                except (KeyError, IndexError, TypeError):
                    if value is not MISSING: problems.append(f"{path} missing")
            for var, path in s["captures"].items():
                vars_[var] = get(j, path)
            count += 1
            failures += bool(problems)
            print(f"  {'PASS' if not problems else 'FAIL'}  {s['name']}" + (f"  -> {problems}" if problems else ""))
    print(f"\n{count - failures}/{count} steps passed")
    return failures

if __name__ == "__main__":
    if sys.argv[1] == "export":
        json.dump(postman(), open(sys.argv[2], "w"), indent=2, ensure_ascii=False)
        print("written", sys.argv[2])
    else:
        sys.exit(run(sys.argv[2]))
