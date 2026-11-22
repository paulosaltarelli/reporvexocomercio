"""Run against a fresh local store seeded by supermarket-keyboard-fixture.php.

Configure APP_ENV=testing, DB_DATABASE=vendra_test_keyboard, KEYBOARD_QA_PASSWORD
and grant the test DB user access to vendra_t_test_keyboard. Run the PHP fixture,
serve the app on localhost, then set KEYBOARD_QA_URL and KEYBOARD_QA_PASSWORD.
Requires Chromium and Python Playwright.
"""
import os
import re
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import expect, sync_playwright


def tab_to(page, locator):
    """Use the same visible tab sequence as the cashier, including modal focus traps."""
    expect(locator).to_be_visible()
    for _ in range(60):
        if locator.evaluate("field => field === document.activeElement"):
            return
        page.keyboard.press("Tab")
    raise AssertionError(f"Control unreachable by Tab: {locator}")


def type_value(page, locator, text):
    tab_to(page, locator)
    page.keyboard.press("Control+a")
    page.keyboard.type(text)


def activate(page, locator):
    tab_to(page, locator)
    page.keyboard.press("Enter")


def capture(page, name):
    directory = os.getenv("KEYBOARD_QA_SCREENSHOTS")
    if directory:
        target = Path(directory)
        target.mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(target / name), animations="disabled")


def run(browser, base, password):
    page = browser.new_page(viewport={"width": 1280, "height": 800}, locale="pt-BR")
    errors, writes = [], []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.on("request", lambda request: writes.append(urlsplit(request.url).path) if request.method == "POST" else None)

    page.goto(base + "/app/keyboard/login")
    type_value(page, page.locator("#email"), "keyboard@example.test")
    type_value(page, page.locator("#password"), password)
    page.keyboard.press("Enter")
    terminal = page.locator("#shift-terminal-number")
    opening = page.locator("#shift-opening-balance")
    type_value(page, terminal, "3")
    type_value(page, opening, "100")
    capture(page, "abertura-turno.png")
    activate(page, page.get_by_role("button", name="Abrir caixa"))
    new_sale = page.locator("#supermarket-new-sale")
    expect(new_sale).to_be_enabled()
    page.wait_for_load_state("domcontentloaded")
    page.keyboard.press("F4")
    expect(new_sale).to_be_focused()
    page.keyboard.press("Enter")

    scanner = page.locator("#supermarket-code")
    expect(scanner).to_be_focused()
    page.wait_for_load_state("domcontentloaded")
    page.keyboard.press("F3")
    quantity = page.locator("#supermarket-quantity")
    expect(quantity).to_be_focused()
    page.keyboard.type("3")
    expect(quantity).to_have_value("3")
    page.keyboard.press("F3")
    page.keyboard.type("1")
    expect(quantity).to_have_value("1")
    page.keyboard.press("F2")
    expect(scanner).to_be_focused()
    page.keyboard.type("Arroz")
    results = page.locator("#supermarket-suggestions").get_by_role("option")
    expect(results).to_have_count(2)
    assert not any(path.endswith("/items") for path in writes)
    page.keyboard.press("ArrowDown")
    page.keyboard.press("ArrowDown")
    expect(results.nth(1)).to_have_attribute("aria-selected", "true")
    page.keyboard.press("Enter")
    expect(page.locator(".operation-last-scan")).to_contain_text("Arroz integral")

    page.keyboard.press("F2")
    expect(scanner).to_be_focused()
    page.keyboard.type("Arroz")
    expect(results).to_have_count(2)
    page.keyboard.press("Escape")
    expect(scanner).to_have_value("")
    expect(results).to_have_count(0)

    page.keyboard.type("7891234567007", delay=0)
    page.keyboard.press("Enter")
    expect(page.locator(".operation-last-scan")).to_contain_text("Arroz agulhinha")
    expect(page.locator(".operation-total")).to_contain_text("25,00")
    assert sum(path.endswith("/items") for path in writes) == 2

    transition = page.locator("#supermarket-cart-transition")
    page.wait_for_load_state("domcontentloaded")
    page.keyboard.press("F8")
    expect(transition).to_be_focused()
    expect(transition).to_have_text("Suspender venda")
    assert not any(path.endswith("/suspend") for path in writes)
    page.keyboard.press("Enter")
    expect(transition).to_have_text("Retomar venda")
    page.wait_for_load_state("domcontentloaded")
    page.keyboard.press("F8")
    expect(transition).to_be_focused()
    assert not any(path.endswith("/resume") for path in writes)
    page.keyboard.press("Enter")
    expect(transition).to_have_text("Suspender venda")
    expect(page.locator(".operation-total")).to_contain_text("25,00")

    payment = page.locator("#operation-payment")
    dialog = page.get_by_role("dialog", name=re.compile("Receber venda"))
    page.wait_for_load_state("domcontentloaded")
    expect(page.locator("#supermarket-checkout-dialog")).to_be_attached()
    page.keyboard.press("F9")
    expect(dialog).to_be_visible()
    expect(payment).to_be_focused()
    page.keyboard.press("F9")
    expect(dialog).to_be_visible()
    page.keyboard.press("F2")
    expect(payment).to_be_focused()
    page.keyboard.press("Escape")
    expect(dialog).to_be_hidden()
    expect(scanner).to_be_focused()
    assert not any(path.endswith("/checkout") for path in writes)

    page.keyboard.press("F9")
    expect(payment).to_be_focused()
    page.keyboard.press("p")
    expect(payment).to_have_value("pix")
    page.keyboard.press("ArrowUp")
    expect(payment).to_have_value("cash")
    capture(page, "recebimento-f9.png")
    confirm = dialog.get_by_role("button", name=re.compile("Confirmar venda"))
    tab_to(page, confirm)
    page.keyboard.press("Tab")
    expect(dialog.get_by_role("button", name=re.compile("Voltar ao caixa"))).to_be_focused()
    page.keyboard.press("Shift+Tab")
    expect(confirm).to_be_focused()
    page.keyboard.press("Enter")
    expect(page.get_by_role("link", name="Imprimir comprovante")).to_be_visible()
    assert sum(path.endswith("/checkout") for path in writes) == 1
    page.reload()
    expect(page.get_by_role("link", name="Imprimir comprovante")).to_be_visible()
    assert sum(path.endswith("/checkout") for path in writes) == 1
    shift_panel = page.locator("#supermarket-shift-close")
    activate(page, shift_panel.locator("summary"))
    expect(shift_panel).to_contain_text("125,00")
    type_value(page, page.locator("#shift-counted-balance"), "125")
    tab_to(page, page.locator("#shift-close-reason"))
    page.keyboard.press("i")
    expect(page.locator("#shift-close-reason")).to_have_value("lunch")
    activate(page, shift_panel.get_by_role("button", name="Encerrar meu turno"))
    previous_shift = page.get_by_role("region", name="Meu último fechamento")
    expect(previous_shift).to_contain_text("125,00")
    expect(previous_shift.locator("dt", has_text="Diferença").locator("+ dd")).to_have_text("R$ 0,00")
    type_value(page, terminal, "3")
    type_value(page, opening, "125")
    activate(page, page.get_by_role("button", name="Abrir caixa"))
    expect(new_sale).to_be_enabled()
    assert sum(path.endswith("/shifts") for path in writes) == 2
    assert not errors, errors
    page.close()


if __name__ == "__main__":
    base = os.environ.get("KEYBOARD_QA_URL", "http://127.0.0.1:8000").rstrip("/")
    if urlsplit(base).hostname not in {"127.0.0.1", "localhost", "::1"}:
        raise ValueError("Use the local test server.")
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=os.getenv("CHROMIUM") or shutil.which("chromium"))
        run(browser, base, os.environ["KEYBOARD_QA_PASSWORD"])
        browser.close()
    print("Supermarket keyboard workflow passed in Chromium")
