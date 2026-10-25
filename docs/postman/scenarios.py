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
