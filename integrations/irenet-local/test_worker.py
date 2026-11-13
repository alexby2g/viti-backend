import json
import unittest
from unittest.mock import MagicMock, patch

import worker


class WorkerSecurityTest(unittest.TestCase):
    def test_secret_and_https_are_required(self):
        with patch.object(worker, "API_BASE", "http://viti-core-api-alexby2g.onrender.com/api/v1"), \
             patch.object(worker, "TOKEN", "a" * 40):
            with self.assertRaises(RuntimeError):
                worker.ensure_safe_config()

        with patch.object(worker, "API_BASE", "https://viti-core-api-alexby2g.onrender.com/api/v1"), \
             patch.object(worker, "TOKEN", ""):
            with self.assertRaises(RuntimeError):
                worker.ensure_safe_config()

        with patch.object(worker, "API_BASE", "https://viti-core-api-alexby2g.onrender.com/api/v1"), \
             patch.object(worker, "TOKEN", "a" * 40):
            worker.ensure_safe_config()

    def test_local_model_is_called_only_on_loopback_and_without_tool_execution(self):
        mocked = MagicMock()
        mocked.__enter__.return_value.read.return_value = json.dumps({
            "message": {"role": "assistant", "content": "Necesitas módulos de ventas e inventario."}
        }).encode("utf-8")

        with patch.object(worker, "urlopen", return_value=mocked) as invoked:
            output = worker.ask_ollama("Diseña un sistema web")

        self.assertIn("ventas", output)
        req = invoked.call_args.args[0]
        self.assertEqual(req.full_url, "http://127.0.0.1:11434/api/chat")
        payload = json.loads(req.data)
        self.assertEqual(payload["model"], "qwen2.5:7b")
        self.assertFalse(payload["stream"])
        self.assertEqual(payload["messages"][-1]["content"], "Diseña un sistema web")
        self.assertNotIn("tools", payload)
        self.assertNotIn("format", payload)
        self.assertNotIn("exec(", worker.ask_ollama.__code__.co_names)

    def test_api_bridge_does_not_follow_redirects(self):
        with patch.object(worker, "TOKEN", "a" * 40):
            with patch.object(worker, "build_opener") as mocked_build:
                response = MagicMock()
                response.__enter__.return_value.status = 204
                mocked_build.return_value.open.return_value = response
                result = worker.api_call("GET", "/assistant/local/worker/next")

                self.assertIsNone(result)
                opener_args = mocked_build.call_args.args
                no_redirect = [h for h in opener_args if h.__class__.__name__ == "NoRedirect"]
                self.assertEqual(len(no_redirect), 1)
                self.assertIsNone(no_redirect[0].redirect_request(None, None, 302, "", None, "https://evil.invalid"))
                self.assertEqual(mocked_build.return_value.open.call_args.args[0].get_header("X-irenet-bridge-token"), "a" * 40)


if __name__ == "__main__":
    unittest.main()
