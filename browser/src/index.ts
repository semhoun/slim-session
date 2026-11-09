export type Transport = 'cookie' | 'header';
export type SessionStatus = 'uninitialized' | 'authenticated' | 'unauthenticated' | 'destroyed';

/** Server deadlines are Unix seconds. They are scheduling hints, not authentication proof. */
export interface SessionMetadata {
  tokenExpiresAt: number;
  idleExpiresAt: number;
  absoluteExpiresAt: number;
}

export interface SessionState {
  readonly status: SessionStatus;
  readonly metadata: Readonly<SessionMetadata> | null;
  readonly revocation: 'none' | 'confirmed' | 'unconfirmed';
}

export interface SessionClientOptions {
  /** Absolute trusted origin and path prefix, for example https://example.test/api/. */
  baseUrl: string;
  application: string;
  transport: Transport;
  endpoints: { status: string; refresh: string; logout: string };
  token?: string;
  storage?: 'memory' | 'sessionStorage';
  credentialHeader?: string;
  credentialPrefix?: string;
  responseTokenHeader?: string;
  csrfHeader?: string;
  refreshAheadSeconds?: number;
  autoRefresh?: boolean;
  /** Inject a compatible implementation for testing or non-browser runtimes. */
  fetch?: typeof fetch;
}

export interface LogoutResult { revoked: boolean }
export type SessionListener = (state: SessionState) => void;

export class SessionError extends Error {
  constructor(message: string, public readonly status?: number) {
    super(message);
    this.name = 'SessionError';
  }
}

const unsafe = (method: string): boolean => !['GET', 'HEAD', 'OPTIONS'].includes(method);
const deadlineHeaders = ['X-Session-Token-Expires-At', 'X-Session-Idle-Expires-At', 'X-Session-Absolute-Expires-At'] as const;

export class SessionClient {
  private readonly base: URL;
  private readonly endpoints: Record<'status' | 'refresh' | 'logout', URL>;
  private readonly options: SessionClientOptions;
  private readonly listeners = new Set<SessionListener>();
  private readonly controllers = new Set<AbortController>();
  private readonly storageKey: string;
  private readonly credentialHeader: string;
  private readonly responseTokenHeader: string;
  private readonly csrfHeader: string;
  private token: string | null = null;
  private csrf: string | null = null;
  private generation = 0;
  private timer: ReturnType<typeof setTimeout> | undefined;
  private refreshPromise: Promise<SessionState> | undefined;
  private initializePromise: Promise<SessionState> | undefined;
  private refreshFailures = 0;
  private current: SessionState = Object.freeze({ status: 'uninitialized', metadata: null, revocation: 'none' });

  constructor(options: SessionClientOptions) {
    this.options = { ...options, endpoints: { ...options.endpoints } };
    this.base = new URL(options.baseUrl);
    if (!['http:', 'https:'].includes(this.base.protocol) || this.base.username || this.base.password || this.base.search || this.base.hash) {
      throw new TypeError('baseUrl must be an absolute HTTP(S) URL without credentials, query, or fragment');
    }
    if (!this.base.pathname.endsWith('/')) this.base.pathname += '/';
    if (!options.application || !['header', 'cookie'].includes(options.transport)) throw new TypeError('Invalid application or transport');
    if (options.transport === 'cookie' && (options.token || options.storage === 'sessionStorage')) throw new TypeError('Cookie credentials must remain HttpOnly');
    const ahead = options.refreshAheadSeconds ?? 3600;
    if (!Number.isFinite(ahead) || ahead < 0) throw new TypeError('Invalid refresh window');
    this.credentialHeader = options.credentialHeader ?? 'Authorization';
    this.responseTokenHeader = options.responseTokenHeader ?? 'X-Session-Token';
    this.csrfHeader = options.csrfHeader ?? 'X-CSRF-Token';
    for (const header of [this.credentialHeader, this.responseTokenHeader, this.csrfHeader]) new Headers({ [header]: 'validation' });
    if (this.credentialHeader.toLowerCase() === this.csrfHeader.toLowerCase()) throw new TypeError('Credential and CSRF headers must differ');
    if (/[\r\n]/.test(options.credentialPrefix ?? 'Bearer ')) throw new TypeError('Invalid credential prefix');
    this.endpoints = {
      status: this.trusted(options.endpoints.status),
      refresh: this.trusted(options.endpoints.refresh),
      logout: this.trusted(options.endpoints.logout),
    };
    for (const url of Object.values(this.endpoints)) {
      if (url.search || url.hash) throw new TypeError('Session endpoints cannot contain queries or fragments');
    }
    this.storageKey = `slim-session:${encodeURIComponent(options.application)}:${encodeURIComponent(this.base.href)}`;
    if (options.transport === 'header') this.token = options.token ? this.validToken(options.token) : this.readToken();
    if (options.token) this.persistToken();
  }

  get state(): SessionState { return this.current; }

  /** Listeners receive immutable state, never credentials or CSRF secrets. */
  subscribe(listener: SessionListener): () => void {
    this.assertAlive();
    this.listeners.add(listener);
    return () => { this.listeners.delete(listener); };
  }

  initialize(): Promise<SessionState> {
    this.assertAlive();
    if (this.initializePromise) return this.initializePromise;
    const task = this.loadStatus();
    this.initializePromise = task;
    void task.finally(() => { if (this.initializePromise === task) this.initializePromise = undefined; }).catch(() => {});
    return task;
  }

  /** Sends once only. A 401 or network failure never replays application work. */
  async request(input: string | URL, init: RequestInit = {}): Promise<Response> {
    this.assertAlive();
    const url = this.trusted(input);
    const generation = this.generation;
    const response = await this.send(url, init);
    if (generation !== this.generation) throw new SessionError('Stale authentication response');
    if (response.status === 401) this.clear('unauthenticated');
    else this.consumeHeaders(response);
    return response;
  }

  refresh(): Promise<SessionState> {
    this.assertAlive();
    if (this.refreshPromise) return this.refreshPromise;
    const task = this.refreshSession();
    this.refreshPromise = task;
    void task.finally(() => { if (this.refreshPromise === task) this.refreshPromise = undefined; }).catch(() => {});
    return task;
  }

  /** Always clears local credentials. Only a successful server response confirms revocation. */
  async logout(): Promise<LogoutResult> {
    this.assertAlive();
    const token = this.token;
    const csrf = this.csrf;
    this.invalidate();
    const generation = this.generation;
    this.token = null;
    this.csrf = null;
    this.persistToken();
    this.publish('unauthenticated', null, 'unconfirmed');
    try {
      const response = await this.send(this.endpoints.logout, { method: 'POST' }, { token, csrf });
      const revoked = response.ok;
      if (generation === this.generation) this.publish('unauthenticated', null, revoked ? 'confirmed' : 'unconfirmed');
      return { revoked };
    } catch {
      return { revoked: false };
    }
  }

  /** Local teardown is not server-side logout. */
  destroy(): void {
    if (this.current.status === 'destroyed') return;
    this.clear('destroyed');
    this.listeners.clear();
  }

  private trusted(input: string | URL): URL {
    const url = new URL(input, this.base);
    if (url.origin !== this.base.origin || !url.pathname.startsWith(this.base.pathname) || url.username || url.password || url.hash || /%2f|%5c/i.test(url.pathname)) {
      throw new TypeError('URL is outside the configured session origin/path');
    }
    return url;
  }

  private assertAlive(): void {
    if (this.current.status === 'destroyed') throw new SessionError('Session client has been destroyed');
  }

  private validToken(token: string): string {
    if (!token || token.length > 16384 || /[\s\x00-\x1f\x7f]/.test(token)) throw new SessionError('Invalid session credential');
    return token;
  }

  private credentialIdentity(token: string | null): string | null {
    if (!token) return null;
    try {
      const payload = token.split('.')[1];
      if (!payload) return null;
      const value: unknown = JSON.parse(globalThis.atob(payload.replace(/-/g, '+').replace(/_/g, '/')));
      if (!value || typeof value !== 'object') return null;
      const sub = (value as Record<string, unknown>).sub;
      return typeof sub === 'string' && sub ? sub : null;
    } catch { return null; }
  }

  private storage(): Storage | undefined {
    if (this.options.storage !== 'sessionStorage' || this.options.transport !== 'header') return undefined;
    try { return globalThis.sessionStorage; } catch { return undefined; }
  }

  private readToken(): string | null {
    try {
      const token = this.storage()?.getItem(this.storageKey);
      return token ? this.validToken(token) : null;
    } catch { return null; }
  }

  private persistToken(): void {
    try {
      const storage = this.storage();
      if (this.token) storage?.setItem(this.storageKey, this.token);
      else storage?.removeItem(this.storageKey);
    } catch { /* Memory remains authoritative if browser storage is unavailable. */ }
  }

  private async send(url: URL, init: RequestInit, credentials = { token: this.token, csrf: this.csrf }): Promise<Response> {
    const headers = new Headers(init.headers);
    if (headers.has(this.csrfHeader) || (this.options.transport === 'header' && headers.has(this.credentialHeader))) {
      throw new TypeError('Session proof headers are managed by the client');
    }
    const method = (init.method ?? 'GET').toUpperCase();
    if (this.options.transport === 'header' && credentials.token) headers.set(this.credentialHeader, (this.options.credentialPrefix ?? 'Bearer ') + credentials.token);
    if (this.options.transport === 'cookie' && unsafe(method)) {
      if (!credentials.csrf) throw new SessionError('Initialize the cookie session before sending mutations');
      headers.set(this.csrfHeader, credentials.csrf);
    }
    const controller = new AbortController();
    const abort = (): void => { controller.abort(init.signal?.reason); };
    init.signal?.addEventListener('abort', abort, { once: true });
    if (init.signal?.aborted) abort();
    this.controllers.add(controller);
    try {
      const fetcher = this.options.fetch ?? globalThis.fetch;
      if (!fetcher) throw new SessionError('A fetch implementation is required');
      const response = await fetcher(url.href, {
        ...init, method, headers, signal: controller.signal,
        credentials: this.options.transport === 'cookie' ? 'include' : 'omit',
        redirect: 'error', cache: 'no-store', referrerPolicy: 'no-referrer',
      });
      if (response.redirected || (response.status >= 300 && response.status < 400)) throw new SessionError('Authenticated redirects are refused');
      return response;
    } finally {
      this.controllers.delete(controller);
      init.signal?.removeEventListener('abort', abort);
    }
  }

  private async loadStatus(): Promise<SessionState> {
    const generation = this.generation;
    const response = await this.send(this.endpoints.status, { method: 'GET' });
    await this.consumeStatus(response, generation);
    return this.current;
  }

  private async refreshSession(): Promise<SessionState> {
    const generation = this.generation;
    const response = await this.send(this.endpoints.refresh, { method: 'POST' });
    await this.consumeStatus(response, generation);
    return this.current;
  }

  private metadata(value: unknown): SessionMetadata {
    if (!value || typeof value !== 'object') throw new SessionError('Invalid session metadata');
    const data = value as Record<string, unknown>;
    for (const field of ['tokenExpiresAt', 'idleExpiresAt', 'absoluteExpiresAt']) {
      if (typeof data[field] !== 'number' || !Number.isSafeInteger(data[field]) || data[field] <= 0) throw new SessionError('Invalid session deadline');
    }
    return { tokenExpiresAt: data.tokenExpiresAt as number, idleExpiresAt: data.idleExpiresAt as number, absoluteExpiresAt: data.absoluteExpiresAt as number };
  }

  private async consumeStatus(response: Response, generation: number): Promise<void> {
    if (generation !== this.generation) return;
    if (response.status === 401) { this.clear('unauthenticated'); return; }
    if (!response.ok) throw new SessionError('Session endpoint failed', response.status);
    const value: unknown = await response.json();
    if (generation !== this.generation) return;
    if (!value || typeof value !== 'object') throw new SessionError('Invalid session status');
    const data = value as Record<string, unknown>;
    if (data.transport !== this.options.transport || typeof data.authenticated !== 'boolean') throw new SessionError('Invalid session transport/status');
    if (!data.authenticated) { this.clear('unauthenticated'); return; }
    const metadata = this.metadata(data);
    let csrf: string | null = null;
    if (this.options.transport === 'cookie') {
      if (typeof data.csrfToken !== 'string' || !data.csrfToken || data.csrfToken.length > 4096 || /[\r\n]/.test(data.csrfToken)) throw new SessionError('Missing or invalid CSRF proof');
      csrf = data.csrfToken;
    }
    this.accept(metadata, response, csrf);
  }

  private consumeHeaders(response: Response): void {
    const values = deadlineHeaders.map(header => response.headers.get(header));
    if (values.every(value => value === null)) {
      // A replacement credential without its deadlines cannot be scheduled safely.
      if (this.options.transport === 'header' && response.headers.has(this.responseTokenHeader)) throw new SessionError('Replacement credential lacks expiration metadata');
      return;
    }
    const metadata = this.metadata({ tokenExpiresAt: Number(values[0]), idleExpiresAt: Number(values[1]), absoluteExpiresAt: Number(values[2]) });
    const csrf = response.headers.get(this.csrfHeader) ?? this.csrf;
    this.accept(metadata, response, csrf);
  }

  private accept(metadata: SessionMetadata, response: Response, csrf: string | null): void {
    let replacement = this.options.transport === 'header' ? response.headers.get(this.responseTokenHeader) : null;
    if (replacement !== null) replacement = this.validToken(replacement);
    const identity = this.credentialIdentity(this.token);
    const sameSession = this.options.transport === 'cookie'
      ? this.csrf !== null && csrf === this.csrf
      : replacement === null || replacement === this.token || (identity !== null && identity === this.credentialIdentity(replacement));
    const current = this.current.metadata;
    if (sameSession && current !== null) {
      // Responses can arrive out of order even though server writes are serialized.
      if (metadata.tokenExpiresAt < current.tokenExpiresAt) {
        replacement = null;
        metadata = { ...metadata, tokenExpiresAt: current.tokenExpiresAt };
      }
      metadata = { ...metadata, idleExpiresAt: Math.max(metadata.idleExpiresAt, current.idleExpiresAt) };
    }
    if (Math.min(metadata.tokenExpiresAt, metadata.idleExpiresAt, metadata.absoluteExpiresAt) * 1000 <= Date.now()) {
      this.clear('unauthenticated');
      return;
    }
    if (replacement !== null) {
      const token = replacement;
      if (token !== this.token) {
        // Unverified identity is only an ordering hint, never an authentication
        // or expiration source. Same-session renewal must not hide a later rotation.
        const before = this.credentialIdentity(this.token);
        const after = this.credentialIdentity(token);
        if (before === null || after === null || before !== after) this.generation++;
        this.token = token;
        this.persistToken();
      }
    }
    if (this.options.transport === 'header' && !this.token) throw new SessionError('Authenticated header session has no credential');
    if (this.options.transport === 'cookie' && this.csrf !== null && csrf !== this.csrf) this.generation++;
    this.csrf = csrf;
    this.refreshFailures = 0;
    this.publish('authenticated', metadata);
    this.schedule();
  }

  private publish(status: SessionStatus, metadata: SessionMetadata | null, revocation: SessionState['revocation'] = 'none'): void {
    this.current = Object.freeze({ status, metadata: metadata ? Object.freeze({ ...metadata }) : null, revocation });
    for (const listener of this.listeners) {
      try { listener(this.current); } catch { /* Observers cannot interrupt security cleanup. */ }
    }
  }

  private invalidate(): void {
    this.generation++;
    clearTimeout(this.timer);
    this.timer = undefined;
    for (const controller of this.controllers) controller.abort();
    this.controllers.clear();
    this.refreshPromise = undefined;
    this.initializePromise = undefined;
  }

  private clear(status: 'unauthenticated' | 'destroyed'): void {
    this.invalidate();
    this.token = null;
    this.csrf = null;
    this.persistToken();
    this.publish(status, null);
  }

  private schedule(retryDelay?: number): void {
    clearTimeout(this.timer);
    const metadata = this.current.metadata;
    if (!metadata || this.current.status !== 'authenticated') return;
    const expiresIn = Math.min(metadata.tokenExpiresAt, metadata.idleExpiresAt, metadata.absoluteExpiresAt) * 1000 - Date.now();
    if (expiresIn <= 0) { this.clear('unauthenticated'); return; }
    const auto = this.options.autoRefresh !== false && this.refreshFailures <= 2;
    const refreshIn = Math.max(1000, metadata.tokenExpiresAt * 1000 - Date.now() - (this.options.refreshAheadSeconds ?? 3600) * 1000);
    const delay = Math.min(expiresIn, auto ? (retryDelay ?? refreshIn) : expiresIn, 2_147_483_647);
    this.timer = setTimeout(() => {
      this.timer = undefined;
      if (Math.min(metadata.tokenExpiresAt, metadata.idleExpiresAt, metadata.absoluteExpiresAt) * 1000 <= Date.now()) {
        this.clear('unauthenticated');
        return;
      }
      if (!auto) { this.schedule(); return; }
      const generation = this.generation;
      void this.refresh().catch(() => {
        if (generation !== this.generation || this.current.status !== 'authenticated') return;
        this.refreshFailures++;
        this.schedule(Math.min(30_000, 1000 * 2 ** this.refreshFailures));
      });
    }, delay);
    // Node consumers should not be kept alive merely by session scheduling.
    (this.timer as unknown as { unref?: () => void }).unref?.();
  }
}

export function createSessionClient(options: SessionClientOptions): SessionClient {
  return new SessionClient(options);
}
