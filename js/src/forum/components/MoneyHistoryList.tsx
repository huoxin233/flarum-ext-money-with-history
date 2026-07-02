import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type UserMoneyHistory from '../models/UserMoneyHistory';

import MoneyHistoryListItem from './MoneyHistoryListItem';

interface MoneyHistoryListAttrs extends ComponentAttrs {
  params: {
    user: User;
  };
}

export default class MoneyHistoryList extends Component<MoneyHistoryListAttrs> {
  loading = true;
  moreResults = false;
  historyEntries: UserMoneyHistory[] = [];
  user!: User;

  oninit(vnode: Mithril.Vnode<MoneyHistoryListAttrs>) {
    super.oninit(vnode);
    this.user = this.attrs.params.user;
    this.loadResults();
  }

  view(): Mithril.Children {
    return (
      <div>
        <div className="MoneyHistoryList-title">{app.translator.trans('huoxin-money-with-history.forum.title')}</div>
        <ul className="MoneyHistoryList-list">
          {this.historyEntries.map((historyEntry) => (
            <li className="MoneyHistoryList-item" key={historyEntry.id()} data-id={historyEntry.id()}>
              <MoneyHistoryListItem historyEntry={historyEntry} />
            </li>
          ))}
        </ul>

        {!this.loading && this.historyEntries.length === 0 && (
          <div>
            <div className="MoneyHistoryList-empty">{app.translator.trans('huoxin-money-with-history.forum.list-empty')}</div>
          </div>
        )}

        {!this.loading && this.hasMoreResults() && (
          <div className="MoneyHistoryList-loadMore">
            <Button className={'Button Button--primary'} onclick={() => this.loadMore()}>
              {app.translator.trans('huoxin-money-with-history.forum.money-list-load-more')}
            </Button>
          </div>
        )}

        {this.loading && (
          <div className="MoneyHistoryList-loading">
            <LoadingIndicator />
          </div>
        )}
      </div>
    );
  }

  loadMore(): void {
    this.loading = true;
    this.loadResults(this.historyEntries.length);
  }

  parseResults(historyEntries: UserMoneyHistory[] & { payload?: { links?: { next?: string } } }): UserMoneyHistory[] {
    this.moreResults = !!historyEntries.payload?.links?.next;
    this.historyEntries.push(...historyEntries);
    this.loading = false;
    m.redraw();

    return historyEntries;
  }

  hasMoreResults(): boolean {
    return this.moreResults;
  }

  loadResults(offset = 0): Promise<UserMoneyHistory[]> {
    this.loading = true;
    return app.store
      .find<UserMoneyHistory[]>('userMoneyHistory', {
        filter: { user: this.user.id() },
        include: 'actor',
        page: { offset },
      })
      .then(this.parseResults.bind(this))
      .catch((err) => {
        this.loading = false;
        m.redraw();
        throw err;
      });
  }
}
