"""Two disposable consumers on one host: real cookie jar and isolated accounts."""

import http.cookiejar
import json
import os
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from html.parser import HTMLParser


class FormToken(HTMLParser):
    token = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name") == "_csrf_token":
            self.token = attrs.get("value")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, target):
        return None


def request(opener, url, data=None):
    try:
        response = opener.open(url, data, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.headers, response.read().decode()


def provision(root):
    email = "isolation-" + secrets.token_hex(8) + "@example.test"
    password = "auth-canary-" + secrets.token_hex(20)
    process = subprocess.run(
        [os.path.join(root, "bin/dev"), "console", "app:account:provision", email, "--password-stdin", "--no-interaction"],
        input=password.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45,
    )
    if process.returncode:
        raise RuntimeError("Consumer provisioning failed.")
    identifier = str(uuid.UUID(process.stdout.strip().decode()))
    return {"email": email, "password": password, "id": identifier}


def login(opener, base, account, expected="/account"):
    status, _, body = request(opener, base + "/login")
    parser = FormToken()
    parser.feed(body)
    if status != 200 or not parser.token:
        raise RuntimeError("Consumer login form failed.")
    data = urllib.parse.urlencode({"_username": account["email"], "_password": account["password"], "_csrf_token": parser.token}).encode()
    status, headers, body = request(opener, base + "/login", data)
    if status != 302 or urllib.parse.urlparse(headers.get("Location", "")).path != expected:
        raise RuntimeError("Consumer login failed.")
    if account["password"] in body:
        raise RuntimeError("Consumer response exposed credentials.")


def main():
    root, peer, address, peer_address = sys.argv[1:]
    base = "http://" + address
    other = "http://" + peer_address
    if urllib.parse.urlparse(base).hostname != urllib.parse.urlparse(other).hostname or address == peer_address:
        raise RuntimeError("Consumer cookie isolation requires two different ports on the same host.")
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
    first, second = provision(root), provision(peer)
    login(opener, base, first)
    status, headers, _ = request(opener, other + "/account")
    if status != 302 or urllib.parse.urlparse(headers.get("Location", "")).path != "/login":
        raise RuntimeError("Foreign consumer cookie authenticated another instance.")
    login(opener, other, first, expected="/login")
    status, headers, _ = request(opener, other + "/account")
    if status != 302 or urllib.parse.urlparse(headers.get("Location", "")).path != "/login":
        raise RuntimeError("Foreign consumer credentials authenticated another database.")
    login(opener, other, second)
    cookies = list(jar)
    names = {cookie.name for cookie in cookies}
    if len(names) != 2 or any(not name.startswith("dm_") or not name.endswith("_dev") for name in names):
        raise RuntimeError("Consumer cookie namespaces collided.")
    for cookie in cookies:
        # CookieJar preserves extension-attribute spelling; HTTP cookie attribute
        # names are case-insensitive and Symfony emits httponly/samesite lowercase.
        attributes = {name.casefold(): value for name, value in cookie._rest.items()}
        requirements = {
            "host-only": not cookie.domain_specified,
            "root-path": cookie.path == "/",
            "httponly": "httponly" in attributes,
            "samesite-lax": str(attributes.get("samesite", "")).casefold() == "lax",
        }
        for category, satisfied in requirements.items():
            if not satisfied:
                raise RuntimeError("Unsafe consumer cookie attribute: " + category + ".")
    for target, account, foreign in [(base, first, second), (other, second, first)]:
        status, _, body = request(opener, target + "/account")
        if status != 200 or account["id"] not in body or account["email"] not in body or foreign["id"] in body:
            raise RuntimeError("Same-host consumer sessions or accounts were not isolated.")
    tokens = []
    for target, account in [(base, first), (other, second)]:
        login_request = urllib.request.Request(
            target + "/api/login",
            data=json.dumps({"email": account["email"], "password": account["password"]}).encode(),
            headers={"Content-Type": "application/json"},
        )
        status, headers, body = request(opener, login_request)
        result = json.loads(body)
        if status != 200 or result.get("token_type") != "Bearer" or result.get("expires_in") != 900 or headers.get_all("Set-Cookie"):
            raise RuntimeError("Consumer stateless JSON login failed.")
        tokens.append(result["access_token"])
    if tokens[0] == tokens[1]:
        raise RuntimeError("Consumer JWT identities collided.")
    for index, (target, account) in enumerate([(base, first), (other, second)]):
        for token, expected in [(tokens[index], 200), (tokens[1 - index], 401)]:
            identity_request = urllib.request.Request(target + "/api/me", headers={"Authorization": "Bearer " + token})
            status, headers, body = request(opener, identity_request)
            if status != expected or headers.get_all("Set-Cookie"):
                raise RuntimeError("Foreign JWT authenticated another consumer or used the existing web session.")
            if status == 200 and json.loads(body).get("id") != account["id"]:
                raise RuntimeError("Consumer bearer identity differed from its account.")
    print("Verified two disposable same-host/different-port consumers: distinct host-only cookie namespaces, simultaneous native sessions and isolated account identities.")
    print("Verified independent JSON login and bidirectional cross-instance JWT rejection despite authenticated web cookies.")


if __name__ == "__main__":
    try:
        main()
    except RuntimeError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("Consumer authentication isolation verification failed.", file=sys.stderr)
        sys.exit(1)
