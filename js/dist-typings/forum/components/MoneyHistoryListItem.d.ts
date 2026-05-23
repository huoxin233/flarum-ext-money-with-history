import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type UserMoneyHistory from '../models/UserMoneyHistory';
interface MoneyHistoryListItemAttrs extends ComponentAttrs {
    historyEntry: UserMoneyHistory;
}
export default class MoneyHistoryListItem extends Component<MoneyHistoryListItemAttrs> {
    view(): Mithril.Children;
}
export {};
