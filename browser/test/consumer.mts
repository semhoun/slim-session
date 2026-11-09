import { createSessionClient, SessionClient, SessionError } from '../dist/index.js';
import type { SessionClientOptions, SessionMetadata, SessionState, LogoutResult } from '../dist/index.js';

const options: SessionClientOptions = {
  application: 'typed-consumer', baseUrl: 'https://example.test/api/', transport: 'cookie',
  endpoints: { status: 'status', refresh: 'refresh', logout: 'logout' },
};
const session: SessionClient = createSessionClient(options);
const state: SessionState = session.state;
const metadata: Readonly<SessionMetadata> | null = state.metadata;
const logout: Promise<LogoutResult> = session.logout();
const error: Error = new SessionError('typed');
void [metadata, logout, error];
