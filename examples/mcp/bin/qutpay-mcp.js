#!/usr/bin/env node
// Qut Pay MCP server over stdio. Configuration: QUTPAY_API_KEY (required), QUTPAY_BASE_URL (optional).
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createServer, VERSION } from '../src/server.js';

if (process.argv.includes('--version') || process.argv.includes('-v')) {
  console.log(VERSION);
  process.exit(0);
}
if (!process.env.QUTPAY_API_KEY) {
  console.error('qutpay-mcp: QUTPAY_API_KEY is not set. Create a key in the Qut Pay cabinet (https://qut.kz/app → Integrations → API keys) and pass it through the environment, e.g.\n  claude mcp add qutpay -e QUTPAY_API_KEY=qp_test_... -- npx -y @qutpay/mcp');
  process.exit(2);
}
const server = createServer();
await server.connect(new StdioServerTransport());
