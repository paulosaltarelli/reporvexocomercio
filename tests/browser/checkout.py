"""Chromium checkout regression test. Requires PHP with Composer dependencies and Python Playwright.

Run python tests/browser/checkout.py, or pass a directory containing pos/items/document/split/stale.html
rendered by checkout-fixture.php for each corresponding mode.
"""
import os
import shutil
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]


def fixture(mode):
    if len(sys.argv) > 1:
        return (Path(sys.argv[1]) / f"{mode}.html").read_text()
    return subprocess.check_output(
        ["php", str(Path(__file__).with_name("checkout-fixture.php")), mode], text=True
    )


def rows(page):
    return page.locator("#items .sale-line-block")


def run(page):
    def serve(route):
        path = urlparse(route.request.url).path.removeprefix("/")
        if path in {"pos", "items", "document", "split", "stale"}:
            html = fixture(path)
            if path == "pos":
                # Generate a larger catalog before the real index is initialized.
                html = html.replace('<script src="/sale-items.js', '''<script>
                const catalog=document.querySelector('#pos-catalog'), item=catalog.options[1];
                const batch=document.createDocumentFragment();
                for(let id=3;id<=10000;id++){
                    const option=item.cloneNode(true);
                    option.value='product:'+id;
                    option.dataset.sku='SKU-'+id;
                    option.dataset.barcode='COD-'+id;
                    batch.appendChild(option);
                }
                catalog.appendChild(batch);
                </script><script src="/sale-items.js''')
            route.fulfill(body='<script src="/ui.js"></script>'+html, content_type="text/html")
        elif path in {"ui.js", "sale-items.js", "split-payments.js", "card-fees.js", "pos.js"}:
            route.fulfill(path=ROOT / "public" / path, content_type="text/javascript")
        else:
            route.abort()

    errors=[]
    page.on("pageerror", lambda exc: errors.append(str(exc)))
    page.route("**/*",serve)
    page.goto("http://checkout.test/pos")
    search=page.locator("#pos-search")
    search.fill("cod-1")
    search.press("Enter")
    assert rows(page).count()==1
    page.locator("#pos-payment-method").select_option("credit")
    page.locator("[name=card_brand]").select_option("visa")
    assert page.locator("#pos-net-total").inner_text()=="R$ 100,00"
    page.locator("[name=fee_policy]").select_option("absorb")
    assert page.locator("#pos-net-total").inner_text()=="R$ 95,00"
    page.keyboard.press("F4")
    assert page.locator("#pos-payment-method").evaluate("e=>e===document.activeElement")
    page.locator(".split-payments summary").click()
    page.locator(".split-enabled").check()
    part=page.locator(".split-payment-row")
    part.locator("[data-field=payment_option]").select_option("credit")
    part.locator("[data-field=amount]").fill("95")
    part.locator("[data-field=card_brand]").select_option("visa")
    assert page.locator("#pos-net-total").inner_text()=="R$ 100,00"
    page.locator(".split-payments summary").click()
    page.keyboard.press("F4")
    assert part.locator("[data-field=payment_option]").evaluate("e=>e===document.activeElement")
    page.locator(".split-enabled").uncheck()
    page.locator("#add").click()
    page.locator("#pos-catalog").select_option("product:10000")
    page.locator("[data-select]").click()
    assert rows(page).nth(1).locator(".item-id").input_value()=="10000"
    rows(page).nth(1).locator(".pos-pick-item").click()
    page.locator("#pos-catalog").select_option("product:2")
    page.locator("[data-select]").click()
    assert rows(page).nth(1).locator(".item-id").input_value()=="2"
    for number in range(3,51):
        search.fill(f"SKU-{number}")
        search.press("Enter")
    assert rows(page).count()==50
    assert page.locator("#items .choice option").count()==50
    assert page.locator("#pos-catalog option").count()==10001
    search.fill("cod-50")
    search.press("Enter")
    assert rows(page).last.locator(".qty").input_value()=="2.000"
    page.goto("http://checkout.test/items")
    assert rows(page).count()==1 and rows(page).locator(".choice").input_value()=="product:2"
    assert rows(page).locator(".qty").input_value()=="3"
    page.locator("#add").click()
    rows(page).last.locator(".choice").select_option("product:1")
    assert rows(page).last.locator(".item-id").input_value()=="1"
    page.goto("http://checkout.test/document")
    due_date=page.locator("meta[name=expected-due-date]").get_attribute("content")
    for key,value in {"payment_option":"credit","paid":"0","installments":"2","due_date":due_date,"card_installments":"2","card_brand":"visa","fee_policy":"pass","rate_seen":"5.00","charged_seen":"100.00"}.items():
        assert page.locator(f"[name={key}]").input_value()==value, key
    assert page.locator("[name=fee_reviewed]").is_checked()
    page.goto("http://checkout.test/stale")
    assert not page.locator("[name=fee_reviewed]").is_checked()
    page.goto("http://checkout.test/split")
    split=page.locator(".split-payment-row")
    assert split.count()==2 and page.locator(".split-enabled").is_checked()
    assert split.first.locator("[data-field=cash_received]").input_value()=="20.00"
    assert split.last.locator("[data-field=fee_reviewed]").is_checked()
    assert split.last.locator("[data-field=due_date]").input_value()==due_date
    assert split.last.locator("[data-field=charged_seen]").input_value()=="80.00"
    assert page.locator("form").evaluate("e=>e.checkValidity()")
    assert not errors, errors


if __name__=="__main__":
    with sync_playwright() as playwright:
        chromium=playwright.chromium.launch(executable_path=os.getenv("CHROMIUM") or shutil.which("chromium"))
        run(chromium.new_page())
        chromium.close()
    print("Chromium checkout regressions passed")
