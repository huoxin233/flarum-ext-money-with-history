import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import avatar from 'flarum/common/helpers/avatar';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import type UserMoneyHistory from '../models/UserMoneyHistory';

interface MoneyHistoryListItemAttrs extends ComponentAttrs {
  historyEntry: UserMoneyHistory;
}

function buildSourceDescription(historyEntry: UserMoneyHistory): string | Mithril.Children {
  const sourceKey = historyEntry.sourceKey();
  const sourceParams = (historyEntry.sourceParams() || {}) as Record<string, unknown>;

  if (!sourceKey) {
    return historyEntry.source() || '';
  }

  const translationParams: Record<string, unknown> = {};

  Object.entries(sourceParams).forEach(([key, value]) => {
    if (key.endsWith('LinkHref') && typeof value === 'string' && value !== '') {
      translationParams[key.slice(0, -4)] = Link.component({
        href: value,
      });
      return;
    }

    if (value !== null && typeof value === 'object') {
      return;
    }

    if (key.endsWith('Key') && typeof value === 'string') {
      translationParams[key.slice(0, -3)] = app.translator.trans(value);
      return;
    }

    translationParams[key] = value;
  });

  return app.translator.trans(sourceKey, translationParams);
}

export default class MoneyHistoryListItem extends Component<MoneyHistoryListItemAttrs> {
  view(): Mithril.Children {
    const { historyEntry } = this.attrs;
    const createdAt = historyEntry.createdAt();
    const balanceDelta = historyEntry.balanceDelta();
    const sourceDescription = buildSourceDescription(historyEntry);
    const historyId = historyEntry.id();
    const actor = historyEntry.actor() || null;
    const balanceBefore = historyEntry.balanceBefore();
    const balanceAfter = historyEntry.balanceAfter();
    const isDebit = balanceDelta < 0;
    const changeAmount = Math.abs(balanceDelta);
    const moneyType = app.translator.trans(
      isDebit ? 'huoxin-money-with-history.forum.record.money-out' : 'huoxin-money-with-history.forum.record.money-in'
    );
    const moneyTypeStyle = isDebit ? 'color:red' : 'color:green';

    return (
      <div className="moneyHistoryContainer">
        <div style="padding-top: 5px;">
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-type')}: </b>
          <span style={moneyTypeStyle}>{moneyType}</span>&nbsp;|&nbsp;
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-assign-at')}: </b>
          {createdAt}
        </div>

        <div style="padding-top: 5px;">
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-id')}: </b>
          {historyId}&nbsp;|&nbsp;
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-from-user')}: </b>
          <Link href="#" className="moneyHistoryUser" style="color:var(--heading-color)">
            {avatar(actor)} {username(actor)}
          </Link>
          &nbsp;|&nbsp;
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-amount')}: </b>
          {changeAmount}&nbsp;|&nbsp;
          <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-balance')}: </b>
          {balanceBefore}&nbsp;-&gt;&nbsp;{balanceAfter}&nbsp;|&nbsp;
          <span>
            <b>{app.translator.trans('huoxin-money-with-history.forum.record.money-list-transfer-notes')}: </b>
            {sourceDescription}
          </span>
        </div>
      </div>
    );
  }
}
